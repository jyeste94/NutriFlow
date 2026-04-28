<?php

namespace App\Tests\Domain;

use App\Entity\Food;
use App\Entity\MealDiary;
use App\Entity\MealEntry;
use App\Entity\Serving;
use App\Entity\User;
use PHPUnit\Framework\TestCase;

final class MealDiaryTest extends TestCase
{
    public function testRecalculateTotalsTracksEntries(): void
    {
        $diary = (new MealDiary())->setUser((new User())->setFirebaseUid('diary-domain'))->setDate(new \DateTimeImmutable('2026-03-10'));

        $food = (new Food())
            ->setExternalId('food-domain-1')
            ->setName('Chicken')
            ->setLastFetchedAt(new \DateTimeImmutable())
            ->setUpdatedAt(new \DateTimeImmutable());

        $serving = (new Serving())
            ->setFood($food)
            ->setDescription('150g')
            ->setCalories(220.5)
            ->setProteins(42.2)
            ->setCarbs(3.1)
            ->setFats(5.4);

        $entry = (new MealEntry())
            ->setServing($serving)
            ->setMealType('lunch')
            ->setMultiplier(2);

        $diary->addEntry($entry);

        self::assertSame(441.0, $diary->getTotalCalories());
        self::assertSame(84.4, $diary->getTotalProteins());
        self::assertSame(6.2, $diary->getTotalCarbs());
        self::assertSame(10.8, $diary->getTotalFats());

        $diary->removeEntry($entry);

        self::assertSame(0.0, $diary->getTotalCalories());
        self::assertSame(0.0, $diary->getTotalProteins());
        self::assertSame(0.0, $diary->getTotalCarbs());
        self::assertSame(0.0, $diary->getTotalFats());
    }

    public function testServingDecimalAccessorsReturnFloats(): void
    {
        $serving = (new Serving())
            ->setDescription('100g')
            ->setCalories(123.45)
            ->setProteins(10.25)
            ->setCarbs(20.5)
            ->setFats(4.75)
            ->setAmount(100.0);

        self::assertSame(123.45, $serving->getCalories());
        self::assertSame(10.25, $serving->getProteins());
        self::assertSame(20.5, $serving->getCarbs());
        self::assertSame(4.75, $serving->getFats());
        self::assertSame(100.0, $serving->getAmount());
    }
}
