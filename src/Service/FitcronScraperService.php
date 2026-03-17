<?php

namespace App\Service;

use Doctrine\ORM\EntityManagerInterface;
use App\Entity\Exercise;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class FitcronScraperService
{
    private const BASE_URL = 'https://fitcron.com/exercises/';

    public function __construct(
        private HttpClientInterface $client,
        private EntityManagerInterface $em
    ) {}

    /**
     * Scrapes multiple pages of exercises from Fitcron.
     * @param int $pages Maximum number of pagination pages to scrape.
     */
    public function scrapeAll(int $pages = 5, callable $progressCallback = null): array
    {
        $stats = ['added' => 0, 'updated' => 0, 'failed' => 0];

        for ($page = 1; $page <= $pages; $page++) {
            $url = $page === 1 ? self::BASE_URL : self::BASE_URL . "page/{$page}/";
            
            try {
                $response = $this->client->request('GET', $url, [
                    'headers' => [
                        'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                        'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8',
                    ],
                ]);

                if ($response->getStatusCode() !== 200) {
                    break; // No more pages found or rate limited
                }

                $html = $response->getContent();
                $crawler = new Crawler($html);

                // Fitcron uses a custom grid with `div.view-item` containing data attributes
                $articles = $crawler->filter('div.view-item');

                if ($articles->count() === 0) {
                    break;
                }

                $articles->each(function (Crawler $node) use (&$stats, $progressCallback) {
                    try {
                        $name = $node->attr('data-name');
                        if (!$name) return;

                        $detailUrlNode = $node->filter('a');
                        if ($detailUrlNode->count() === 0) return;
                        
                        $detailUrl = $detailUrlNode->attr('href');

                        // Check if exists
                        $exercise = $this->em->getRepository(Exercise::class)->findOneBy(['name' => $name]);
                        $isNew = false;
                        if (!$exercise) {
                            $exercise = new Exercise();
                            $exercise->setName($name);
                            $isNew = true;
                        }

                        // Extract data attributes for Muscule Group and Equipment
                        if ($node->attr('data-main-muscle')) {
                            $exercise->setMuscleGroup($node->attr('data-main-muscle'));
                        }
                        if ($node->attr('data-equipment')) {
                            $exercise->setEquipment($node->attr('data-equipment'));
                        }

                        // Extract GIF
                        $imgNode = $node->filter('img');
                        if ($imgNode->count() > 0) {
                            $src = $imgNode->attr('src') ?? $imgNode->attr('data-src');
                            if ($src) {
                                $exercise->setGifUrl($src);
                            }
                        }

                        // We still need the detail page for Description and Video.
                        $this->scrapeDetailPage($detailUrl, $exercise);

                        $this->em->persist($exercise);
                        $this->em->flush(); // flush one by one to avoid huge memory spikes

                        if ($isNew) {
                            $stats['added']++;
                        } else {
                            $stats['updated']++;
                        }

                        if ($progressCallback) {
                            $progressCallback($name);
                        }

                    } catch (\Exception $e) {
                        $stats['failed']++;
                        // Log or ignore specific exercise failure
                    }
                });
                
                // Be polite to the server
                sleep(1);

            } catch (\Exception $e) {
                break;
            }
        }

        return $stats;
    }

    private function scrapeDetailPage(string $url, Exercise $exercise): void
    {
        try {
            $response = $this->client->request('GET', $url, [
                'headers' => [
                     'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)'
                ]
            ]);

            if ($response->getStatusCode() !== 200) return;

            $crawler = new Crawler($response->getContent());

            // Specific parsing heuristic for Fitcron
            
            // 1. Muscle Group (often in categories or specific tags)
            $catNode = $crawler->filter('.cat-links a');
            if ($catNode->count() > 0) {
                $exercise->setMuscleGroup(trim($catNode->first()->text()));
            }

            // 2. Equipment (often in tags)
            $tagNode = $crawler->filter('.tags-links a');
            if ($tagNode->count() > 0) {
                $exercise->setEquipment(trim($tagNode->first()->text()));
            }

            // 3. Description (Main content body)
            $contentNode = $crawler->filter('.entry-content');
            if ($contentNode->count() > 0) {
                // Get paragraphs, ignoring scripts or ads
                $paragraphs = $contentNode->filter('p')->each(function (Crawler $p) {
                    return trim($p->text());
                });
                $desc = implode("\n\n", array_filter($paragraphs));
                $exercise->setDescription(substr($desc, 0, 2000)); // Cap length
            }

            // 4. Video or GIF (Look for specific embeds)
            $iframeNode = $crawler->filter('.entry-content iframe');
            if ($iframeNode->count() > 0) {
                $iframeSrc = $iframeNode->attr('src');
                if (str_contains($iframeSrc, 'youtube') || str_contains($iframeSrc, 'vimeo')) {
                    $exercise->setVideoUrl($iframeSrc);
                }
            }
            
            // If we didn't get an image from the list, try getting the featured image
            if (!$exercise->getGifUrl()) {
                 $featuredImage = $crawler->filter('.post-thumbnail img')->first();
                 if ($featuredImage->count() > 0) {
                      $exercise->setGifUrl($featuredImage->attr('src'));
                 }
            }

        } catch (\Exception $e) {
            // Silently fail detail scraping and leave basic info intact
        }
    }
}
