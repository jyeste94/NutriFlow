<?php

namespace App\Tests\Api;

use App\Entity\Exercise;
use App\Entity\Food;
use App\Entity\Serving;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

abstract class ApiTestCase extends WebTestCase
{
    protected KernelBrowser $client;
    protected EntityManagerInterface $em;

    protected function setUp(): void
    {
        parent::setUp();

        self::ensureKernelShutdown();
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);

        $this->resetDatabase();
    }

    protected function tearDown(): void
    {
        $this->em->close();
        unset($this->em, $this->client);

        parent::tearDown();
    }

    /**
     * @return array<string, string>
     */
    protected function authHeaders(string $uid = 'test-user', ?string $email = 'test@example.com'): array
    {
        $headers = ['HTTP_X_TEST_USER' => $uid];
        if ($email !== null) {
            $headers['HTTP_X_TEST_EMAIL'] = $email;
        }

        return $headers;
    }

    /**
     * @return array<string, mixed>
     */
    protected function jsonResponse(): array
    {
        $content = $this->client->getResponse()->getContent();
        if ($content === false || $content === '') {
            return [];
        }

        return json_decode($content, true, 512, JSON_THROW_ON_ERROR);
    }

    protected function createExerciseFixture(string $name = 'Push Up'): Exercise
    {
        $exercise = new Exercise();
        $exercise->setName($name);
        $exercise->setMuscleGroup('chest');
        $exercise->setEquipment('bodyweight');

        $this->em->persist($exercise);
        $this->em->flush();

        return $exercise;
    }

    protected function createServingFixture(string $externalId = 'food-ext-1'): Serving
    {
        $food = new Food();
        $food->setExternalId($externalId);
        $food->setName('Test Food');
        $food->setBrand('Test Brand');
        $food->setLastFetchedAt(new \DateTimeImmutable('now'));
        $food->setUpdatedAt(new \DateTimeImmutable('now'));

        $serving = new Serving();
        $serving->setFood($food);
        $serving->setDescription('100g');
        $serving->setCalories(100.0);
        $serving->setProteins(10.0);
        $serving->setCarbs(20.0);
        $serving->setFats(5.0);

        $food->addServing($serving);
        $this->em->persist($food);
        $this->em->persist($serving);
        $this->em->flush();

        return $serving;
    }

    private function resetDatabase(): void
    {
        $metadata = $this->em->getMetadataFactory()->getAllMetadata();
        if ($metadata === []) {
            return;
        }

        $tool = new SchemaTool($this->em);
        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);
        $this->em->clear();
    }
}
