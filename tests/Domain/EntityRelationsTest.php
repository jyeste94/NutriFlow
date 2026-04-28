<?php

namespace App\Tests\Domain;

use App\Entity\DietPlan;
use App\Entity\DietPlanDay;
use App\Entity\DietPlanMeal;
use App\Entity\Exercise;
use App\Entity\Food;
use App\Entity\Routine;
use App\Entity\RoutineExercise;
use App\Entity\Serving;
use App\Entity\User;
use App\Entity\WorkoutSession;
use App\Entity\WorkoutSetLog;
use PHPUnit\Framework\TestCase;

final class EntityRelationsTest extends TestCase
{
    public function testRoutineExerciseAssociationStaysInSync(): void
    {
        $routine = (new Routine())->setName('Push')->setUser((new User())->setFirebaseUid('u1'));
        $exercise = (new Exercise())->setName('Bench')->setMuscleGroup('chest');
        $routineExercise = (new RoutineExercise())->setExercise($exercise);

        $routine->addRoutineExercise($routineExercise);
        self::assertCount(1, $routine->getRoutineExercises());
        self::assertSame($routine, $routineExercise->getRoutine());

        $routine->removeRoutineExercise($routineExercise);
        self::assertCount(0, $routine->getRoutineExercises());
        self::assertNull($routineExercise->getRoutine());
    }

    public function testWorkoutSessionSetAssociationStaysInSync(): void
    {
        $session = (new WorkoutSession())->setUser((new User())->setFirebaseUid('u2'));
        $exercise = (new Exercise())->setName('Squat')->setMuscleGroup('legs');
        $set = (new WorkoutSetLog())->setExercise($exercise)->setReps(5)->setWeight(100);

        $session->addSet($set);
        self::assertCount(1, $session->getSets());
        self::assertSame($session, $set->getSession());

        $session->removeSet($set);
        self::assertCount(0, $session->getSets());
        self::assertNull($set->getSession());
    }

    public function testDietPlanHierarchyAssociationsStayInSync(): void
    {
        $plan = (new DietPlan())->setName('Cut')->setUser((new User())->setFirebaseUid('u3'));
        $day = (new DietPlanDay())->setDayOfWeek('mon');
        $food = (new Food())->setExternalId('food-1')->setName('Rice')->setLastFetchedAt(new \DateTimeImmutable())->setUpdatedAt(new \DateTimeImmutable());
        $serving = (new Serving())->setFood($food)->setDescription('100g')->setCalories(130)->setProteins(2.4)->setCarbs(28)->setFats(0.3);
        $meal = (new DietPlanMeal())->setServing($serving)->setMealType('lunch')->setMultiplier(1.5);

        $plan->addDay($day);
        $day->addMeal($meal);

        self::assertSame($plan, $day->getPlan());
        self::assertSame($day, $meal->getDay());

        $day->removeMeal($meal);
        self::assertNull($meal->getDay());

        $plan->removeDay($day);
        self::assertNull($day->getPlan());
    }

    public function testFoodServingAssociationStaysInSync(): void
    {
        $food = (new Food())->setExternalId('food-2')->setName('Oats')->setLastFetchedAt(new \DateTimeImmutable())->setUpdatedAt(new \DateTimeImmutable());
        $serving = (new Serving())->setDescription('50g')->setCalories(190);

        $food->addServing($serving);
        self::assertSame($food, $serving->getFood());

        $food->removeServing($serving);
        self::assertNull($serving->getFood());
    }
}
