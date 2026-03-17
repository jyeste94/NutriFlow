<?php
require 'vendor/autoload.php';
use Symfony\Component\DomCrawler\Crawler;

$html = file_get_contents('https://fitcron.com/exercises/');
$crawler = new Crawler($html);

// Find ANY article-like container
$nodes = $crawler->filter('article, .post, .elementor-post, .item, .card');
echo "Found " . $nodes->count() . " containers.\n";

if ($nodes->count() > 0) {
    $firstNode = $nodes->first();
    echo "\nFirst container HTML snippet:\n";
    echo substr($firstNode->html(), 0, 500) . "...\n";
} else {
    // Just find any link with /ejercicios/ or /exercises/
    echo "No containers found. Looking for links...\n";
    foreach ($crawler->filter('a') as $a) {
        $href = $a->getAttribute('href');
        if (str_contains($href, '/exercises/') && $href !== 'https://fitcron.com/exercises/') {
            echo trim($a->textContent) . " -> " . $href . "\n";
        }
    }
}
