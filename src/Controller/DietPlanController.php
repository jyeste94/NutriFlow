<?php

namespace App\Controller;

use App\Entity\DietPlan;
use App\Entity\DietPlanDay;
use App\Entity\DietPlanMeal;
use App\Entity\MealDiary;
use App\Entity\MealEntry;
use App\Entity\Serving;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

#[Route('/v1/diet-plans', name: 'api_diet_plans_')]
class DietPlanController extends AbstractController
{
    private const ALLOWED_MEAL_TYPES = ['breakfast', 'almuerzo', 'lunch', 'merienda', 'dinner', 'snack'];
    private const ALLOWED_DAY_KEYS = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];

    public function __construct(
        private EntityManagerInterface $em
    ) {}

    #[Route('', name: 'list', methods: ['GET'])]
    public function list(): JsonResponse
    {
        $user = $this->getUser();
        if (!$user) return $this->json(['error' => 'Unauthorized'], 401);

        $plans = $this->em->getRepository(DietPlan::class)->findBy(
            ['user' => $user],
            ['createdAt' => 'DESC']
        );

        $result = array_map(fn(DietPlan $p) => [
            'id' => $p->getId()->toRfc4122(),
            'name' => $p->getName(),
            'description' => $p->getDescription(),
            'is_default' => $p->isDefault(),
            'day_count' => $p->getDays()->count(),
            'created_at' => $p->getCreatedAt()->format('c'),
        ], $plans);

        return $this->json($result);
    }

    #[Route('/{id}', name: 'get', methods: ['GET'])]
    public function get(string $id): JsonResponse
    {
        $user = $this->getUser();
        if (!$user) return $this->json(['error' => 'Unauthorized'], 401);

        $plan = $this->em->getRepository(DietPlan::class)->find($id);
        if (!$plan) return $this->json(['error' => 'Diet plan not found'], 404);
        if ($plan->getUser() !== $user) return $this->json(['error' => 'Forbidden'], 403);

        return $this->json($this->serializePlan($plan));
    }

    #[Route('', name: 'create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $user = $this->getUser();
        if (!$user) return $this->json(['error' => 'Unauthorized'], 401);

        $data = $this->parseJsonBody($request);
        if ($data instanceof JsonResponse) return $data;

        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') return $this->json(['error' => 'Diet plan name is required'], 400);

        $plan = new DietPlan();
        $plan->setUser($user);
        $plan->setName($name);
        $plan->setDescription(isset($data['description']) ? trim((string) $data['description']) : null);
        $plan->setSupplementProtocol(isset($data['supplement_protocol']) ? trim((string) $data['supplement_protocol']) : null);
        $plan->setIsDefault(!empty($data['is_default']));

        if (array_key_exists('days', $data) && !is_array($data['days'])) {
            return $this->json(['error' => 'days must be an array'], 400);
        }

        $normalizedDays = $this->normalizePlanDays(is_array($data['days'] ?? null) ? $data['days'] : []);
        if ($normalizedDays instanceof JsonResponse) {
            return $normalizedDays;
        }

        if ($plan->isDefault()) {
            $this->unsetDefaultPlansForUser($user);
        }

        $conn = $this->em->getConnection();
        $conn->beginTransaction();
        try {
            $this->em->persist($plan);
            $this->replacePlanDays($plan, $normalizedDays);
            $this->em->flush();
            $conn->commit();
        } catch (\Throwable) {
            if ($conn->isTransactionActive()) {
                $conn->rollBack();
            }

            return $this->json(['error' => 'Could not create diet plan'], 500);
        }

        return $this->json(['message' => 'Diet plan created', 'id' => $plan->getId()->toRfc4122()], 201);
    }

    #[Route('/{id}', name: 'update', methods: ['PUT'])]
    public function update(string $id, Request $request): JsonResponse
    {
        $user = $this->getUser();
        if (!$user) return $this->json(['error' => 'Unauthorized'], 401);

        $plan = $this->em->getRepository(DietPlan::class)->find($id);
        if (!$plan) return $this->json(['error' => 'Diet plan not found'], 404);
        if ($plan->getUser() !== $user) return $this->json(['error' => 'Forbidden'], 403);

        $data = $this->parseJsonBody($request);
        if ($data instanceof JsonResponse) return $data;

        if (isset($data['name'])) {
            $name = trim((string) $data['name']);
            if ($name === '') return $this->json(['error' => 'Diet plan name is required'], 400);
            $plan->setName($name);
        }

        if (array_key_exists('description', $data)) {
            $description = trim((string) $data['description']);
            $plan->setDescription($description !== '' ? $description : null);
        }

        if (array_key_exists('supplement_protocol', $data)) {
            $supplementProtocol = trim((string) $data['supplement_protocol']);
            $plan->setSupplementProtocol($supplementProtocol !== '' ? $supplementProtocol : null);
        }

        if (array_key_exists('is_default', $data)) {
            if (!empty($data['is_default'])) {
                $this->unsetDefaultPlansForUser($user);
            }
            $plan->setIsDefault(!empty($data['is_default']));
        }

        $normalizedDays = null;
        if (array_key_exists('days', $data)) {
            if (!is_array($data['days'])) {
                return $this->json(['error' => 'days must be an array'], 400);
            }

            $normalizedDays = $this->normalizePlanDays($data['days']);
            if ($normalizedDays instanceof JsonResponse) {
                return $normalizedDays;
            }
        }

        $plan->setUpdatedAt(new \DateTimeImmutable());

        $conn = $this->em->getConnection();
        $conn->beginTransaction();
        try {
            if (is_array($normalizedDays)) {
                $this->replacePlanDays($plan, $normalizedDays);
            }

            $this->em->flush();
            $conn->commit();
        } catch (\Throwable) {
            if ($conn->isTransactionActive()) {
                $conn->rollBack();
            }

            return $this->json(['error' => 'Could not update diet plan'], 500);
        }

        return $this->json(['message' => 'Diet plan updated']);
    }

    #[Route('/{id}', name: 'delete', methods: ['DELETE'])]
    public function delete(string $id): JsonResponse
    {
        $user = $this->getUser();
        if (!$user) return $this->json(['error' => 'Unauthorized'], 401);

        $plan = $this->em->getRepository(DietPlan::class)->find($id);
        if (!$plan) return $this->json(['error' => 'Diet plan not found'], 404);
        if ($plan->getUser() !== $user) return $this->json(['error' => 'Forbidden'], 403);

        $this->em->remove($plan);
        $this->em->flush();

        return $this->json(['message' => 'Diet plan deleted']);
    }

    #[Route('/{id}/apply', name: 'apply', methods: ['POST'])]
    public function apply(string $id, Request $request): JsonResponse
    {
        $user = $this->getUser();
        if (!$user) return $this->json(['error' => 'Unauthorized'], 401);

        $plan = $this->em->getRepository(DietPlan::class)->find($id);
        if (!$plan) return $this->json(['error' => 'Diet plan not found'], 404);
        if ($plan->getUser() !== $user) return $this->json(['error' => 'Forbidden'], 403);

        $data = $this->parseJsonBody($request);
        if ($data instanceof JsonResponse) return $data;

        $startDate = isset($data['start_date']) ? $this->parseIsoDate($data['start_date']) : new \DateTimeImmutable();
        if (!$startDate) return $this->json(['error' => 'Invalid start_date format. Use YYYY-MM-DD'], 400);

        // Find Monday of the week containing startDate
        $dayOfWeek = (int) $startDate->format('N'); // 1=Mon, 7=Sun
        $monday = $startDate->modify('-' . ($dayOfWeek - 1) . ' days');

        $dayNames = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];
        $results = [];

        foreach ($plan->getDays() as $planDay) {
            $dayKey = $planDay->getDayOfWeek();
            $dayIndex = array_search($dayKey, $dayNames);
            if ($dayIndex === false) continue;

            $date = $monday->modify('+' . $dayIndex . ' days');

            // Get or create diary for this date
            $diary = $this->em->getRepository(MealDiary::class)->findOneBy([
                'user' => $user, 'date' => $date,
            ]);

            if (!$diary) {
                $diary = new MealDiary();
                $diary->setUser($user);
                $diary->setDate($date);
                $this->em->persist($diary);
            }

            foreach ($planDay->getMeals() as $planMeal) {
                $entry = new MealEntry();
                $entry->setServing($planMeal->getServing());
                $entry->setMealType($planMeal->getMealType());
                $entry->setMultiplier($planMeal->getMultiplier());
                $diary->addEntry($entry);
                $this->em->persist($entry);
                $results[] = $entry->getId()->toRfc4122();
            }
        }

        $this->em->flush();

        return $this->json(['message' => 'Diet plan applied', 'entry_ids' => $results], 201);
    }

    /**
     * @return array<string, mixed>|JsonResponse
     */
    private function serializePlan(DietPlan $plan): array
    {
        $days = [];
        foreach ($plan->getDays() as $day) {
            $meals = [];
            foreach ($day->getMeals() as $meal) {
                $serving = $meal->getServing();
                $food = $serving->getFood();
                $meals[] = [
                    'id' => $meal->getId()->toRfc4122(),
                    'serving_id' => $serving->getId()->toRfc4122(),
                    'meal_type' => $meal->getMealType(),
                    'multiplier' => $meal->getMultiplier(),
                    'food_name' => $food->getName(),
                    'food_brand' => $food->getBrand(),
                    'serving_description' => $serving->getDescription(),
                    'calories' => $serving->getCalories() * $meal->getMultiplier(),
                    'proteins' => $serving->getProteins() * $meal->getMultiplier(),
                    'carbs' => $serving->getCarbs() * $meal->getMultiplier(),
                    'fats' => $serving->getFats() * $meal->getMultiplier(),
                    'option_group' => $meal->getOptionGroup(),
                    'notes' => $meal->getNotes(),
                ];
            }
            $days[] = [
                'day_of_week' => $day->getDayOfWeek(),
                'sort_order' => $day->getSortOrder(),
                'meals' => $meals,
            ];
        }

        return [
            'id' => $plan->getId()->toRfc4122(),
            'name' => $plan->getName(),
            'description' => $plan->getDescription(),
            'supplement_protocol' => $plan->getSupplementProtocol(),
            'is_default' => $plan->isDefault(),
            'days' => $days,
            'created_at' => $plan->getCreatedAt()->format('c'),
            'updated_at' => $plan->getUpdatedAt()?->format('c'),
        ];
    }

    private function parseIsoDate(string $date): ?\DateTimeImmutable
    {
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        $errors = \DateTimeImmutable::getLastErrors();
        if (!$parsed) return null;
        if (is_array($errors) && (($errors['warning_count'] ?? 0) > 0 || ($errors['error_count'] ?? 0) > 0)) return null;
        return $parsed->format('Y-m-d') === $date ? $parsed : null;
    }

    /**
     * @param array<mixed> $days
     * @return array<int, array{day_of_week:string, meals:array<int, array{serving:Serving, meal_type:string, multiplier:float, option_group:?string, notes:?string}>}>|JsonResponse
     */
    private function normalizePlanDays(array $days): array|JsonResponse
    {
        if (count($days) > 7) {
            return $this->json(['error' => 'days cannot contain more than 7 entries'], 400);
        }

        $normalizedDays = [];
        $seenDayKeys = [];

        foreach ($days as $dayIndex => $dayData) {
            if (!is_array($dayData)) {
                return $this->json(['error' => "Invalid day payload at index $dayIndex"], 400);
            }

            $dayKey = strtolower(trim((string) ($dayData['day_of_week'] ?? '')));
            if ($dayKey === '' || !in_array($dayKey, self::ALLOWED_DAY_KEYS, true)) {
                return $this->json(['error' => "Invalid day_of_week at index $dayIndex"], 400);
            }
            if (isset($seenDayKeys[$dayKey])) {
                return $this->json(['error' => 'days cannot contain duplicate day_of_week values'], 400);
            }
            $seenDayKeys[$dayKey] = true;

            $meals = $dayData['meals'] ?? [];
            if (!is_array($meals)) {
                return $this->json(['error' => "meals must be an array at day index $dayIndex"], 400);
            }

            $normalizedMeals = [];
            foreach ($meals as $mealIndex => $mealData) {
                if (!is_array($mealData)) {
                    return $this->json(['error' => "Invalid meal payload at day $dayIndex index $mealIndex"], 400);
                }

                $servingId = trim((string) ($mealData['serving_id'] ?? ''));
                $mealType = strtolower(trim((string) ($mealData['meal_type'] ?? '')));
                $multiplier = filter_var($mealData['multiplier'] ?? 1, FILTER_VALIDATE_FLOAT);

                if (!Uuid::isValid($servingId)) {
                    return $this->json(['error' => "Invalid serving_id at day $dayIndex index $mealIndex"], 400);
                }
                if (!in_array($mealType, self::ALLOWED_MEAL_TYPES, true)) {
                    return $this->json(['error' => "Invalid meal_type at day $dayIndex index $mealIndex"], 400);
                }
                if ($multiplier === false || $multiplier <= 0 || $multiplier > 100) {
                    return $this->json(['error' => "Invalid multiplier at day $dayIndex index $mealIndex"], 400);
                }

                $serving = $this->em->getRepository(Serving::class)->find($servingId);
                if (!$serving instanceof Serving) {
                    return $this->json(['error' => "Serving not found at day $dayIndex index $mealIndex"], 400);
                }

                $optionGroup = null;
                if (array_key_exists('option_group', $mealData)) {
                    $optionGroup = strtoupper(trim((string) $mealData['option_group']));
                    if ($optionGroup === '') {
                        $optionGroup = null;
                    } elseif (mb_strlen($optionGroup) > 10) {
                        return $this->json(['error' => "option_group is too long at day $dayIndex index $mealIndex"], 400);
                    }
                }

                $notes = null;
                if (array_key_exists('notes', $mealData)) {
                    $notes = trim((string) $mealData['notes']);
                    if ($notes === '') {
                        $notes = null;
                    }
                }

                $normalizedMeals[] = [
                    'serving' => $serving,
                    'meal_type' => $mealType,
                    'multiplier' => (float) $multiplier,
                    'option_group' => $optionGroup,
                    'notes' => $notes,
                ];
            }

            $normalizedDays[] = [
                'day_of_week' => $dayKey,
                'meals' => $normalizedMeals,
            ];
        }

        return $normalizedDays;
    }

    /**
     * @param array<int, array{day_of_week:string, meals:array<int, array{serving:Serving, meal_type:string, multiplier:float, option_group:?string, notes:?string}>}> $days
     */
    private function replacePlanDays(DietPlan $plan, array $days): void
    {
        foreach ($plan->getDays() as $day) {
            $this->em->remove($day);
        }
        $plan->getDays()->clear();

        foreach ($days as $dayIndex => $dayData) {
            $day = new DietPlanDay();
            $day->setDayOfWeek($dayData['day_of_week']);
            $day->setSortOrder($dayIndex);
            $plan->addDay($day);

            foreach ($dayData['meals'] as $mealIndex => $mealData) {
                $meal = new DietPlanMeal();
                $meal->setServing($mealData['serving']);
                $meal->setMealType($mealData['meal_type']);
                $meal->setMultiplier($mealData['multiplier']);
                $meal->setSortOrder($mealIndex);
                $meal->setOptionGroup($mealData['option_group']);
                $meal->setNotes($mealData['notes']);
                $day->addMeal($meal);
            }
        }
    }

    private function unsetDefaultPlansForUser(object $user): void
    {
        $this->em->createQueryBuilder()
            ->update(DietPlan::class, 'p')
            ->set('p.isDefault', ':false')
            ->where('p.user = :user')
            ->setParameter('false', false)
            ->setParameter('user', $user)
            ->getQuery()
            ->execute();
    }

    /**
     * @return array<mixed>|JsonResponse
     */
    private function parseJsonBody(Request $request): array|JsonResponse
    {
        if ($request->getContent() === '') return [];
        try {
            $data = json_decode($request->getContent(), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $this->json(['error' => 'Invalid JSON body'], 400);
        }
        if (!is_array($data)) return $this->json(['error' => 'Invalid JSON body'], 400);
        return $data;
    }
}
