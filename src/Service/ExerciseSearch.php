<?php

namespace App\Service;

use App\Entity\Exercise;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query\ResultSetMappingBuilder;

final class ExerciseSearch
{
    private const MAX_QUERY_LENGTH = 200;
    private const MAX_TERMS = 20;

    // Explicit folding keeps SQLite's ASCII-only LOWER consistent with MySQL.
    private const DIACRITICS = [
        'á' => 'a', 'Á' => 'a', 'é' => 'e', 'É' => 'e',
        'í' => 'i', 'Í' => 'i', 'ó' => 'o', 'Ó' => 'o',
        'ú' => 'u', 'Ú' => 'u', 'ü' => 'u', 'Ü' => 'u',
        'ñ' => 'n', 'Ñ' => 'n',
        "\u{0301}" => '', "\u{0308}" => '', "\u{0303}" => '',
    ];

    private const CONNECTORS = [
        'a', 'al', 'con', 'de', 'del', 'el', 'en', 'la', 'las', 'lo', 'los',
        'para', 'por', 'un', 'una', 'unos', 'unas', 'y', 'o', 'e',
        'an', 'and', 'for', 'in', 'of', 'on', 'the', 'to', 'with',
    ];

    private const SYNONYMS = [
        ['banca', 'banco', 'bench'],
        ['mancuerna', 'mancuernas', 'dumbbell', 'dumbbells'],
        ['barra', 'barras', 'barbell', 'barbells'],
        ['remo', 'remos', 'row', 'rows', 'rowing'],
        ['sentadilla', 'sentadillas', 'squat', 'squats'],
        ['muerto', 'muertos', 'deadlift', 'deadlifts'],
        ['peso', 'weight', 'weights'],
        ['plano', 'plana', 'flat'],
        ['inclinado', 'inclinada', 'incline', 'inclined'],
        ['declinado', 'declinada', 'decline', 'declined'],
        ['rumano', 'rumana', 'romanian'],
        ['polea', 'poleas', 'cable', 'cables'],
        ['maquina', 'maquinas', 'machine', 'machines'],
        ['corporal', 'bodyweight', 'body weight'],
        ['jalon', 'jalones', 'pulldown', 'pulldowns', 'pull-down', 'pull down'],
        ['dominada', 'dominadas', 'pullup', 'pullups', 'pull-up', 'pull-ups', 'pull up'],
        ['flexion', 'flexiones', 'pushup', 'pushups', 'push-up', 'push-ups', 'push up'],
        ['apertura', 'aperturas', 'fly', 'flyes', 'flies'],
        ['elevacion', 'elevaciones', 'raise', 'raises'],
        ['extension', 'extensiones', 'extensions'],
        ['curl', 'curls'],
        ['hombro', 'hombros', 'shoulder', 'shoulders'],
        ['pierna', 'piernas', 'leg', 'legs'],
        ['pecho', 'chest'],
        ['espalda', 'back'],
        ['biceps', 'bicep'],
        ['triceps', 'tricep'],
        ['sentado', 'sentada', 'seated'],
        ['agarre', 'grip'],
        ['abierto', 'abierta', 'wide'],
        ['cerrado', 'cerrada', 'close', 'narrow'],
    ];

    public function __construct(private EntityManagerInterface $em)
    {
    }

    /** @return array{exercises: list<Exercise>, total: int} */
    public function search(string $query, int $page, int $limit): array
    {
        if (mb_strlen(trim($query)) > self::MAX_QUERY_LENGTH) {
            throw new \InvalidArgumentException('La búsqueda admite como máximo 200 caracteres.');
        }
        $tokens = $this->tokens($query);
        if (count($tokens) > self::MAX_TERMS) {
            throw new \InvalidArgumentException('La búsqueda admite como máximo 20 términos distintos.');
        }
        if ($tokens === []) {
            return ['exercises' => [], 'total' => 0];
        }

        $connection = $this->em->getConnection();
        $qb = $connection->createQueryBuilder()->from('exercises', 'e');
        $columns = array_map($this->foldColumn(...), ['name', 'name_en', 'name_es', 'source_name', 'equipment', 'description']);

        foreach ($tokens as $index => $token) {
            $alternatives = [$token];
            foreach (self::SYNONYMS as $synonyms) {
                if (in_array($token, $synonyms, true)) {
                    $alternatives = $synonyms;
                    break;
                }
            }

            $matches = [];
            foreach ($alternatives as $alternativeIndex => $alternative) {
                $parameter = 'token_' . $index . '_' . $alternativeIndex;
                foreach ($columns as $column) {
                    $matches[] = $column . ' LIKE :' . $parameter . " ESCAPE '!'";
                }
                $literal = strtr($alternative, ['!' => '!!', '%' => '!%', '_' => '!_']);
                $qb->setParameter($parameter, '%' . $literal . '%');
            }
            $qb->andWhere('(' . implode(' OR ', $matches) . ')');
        }

        $total = (int) (clone $qb)->select('COUNT(e.id)')->executeQuery()->fetchOne();
        $mapping = new ResultSetMappingBuilder($this->em);
        $mapping->addRootEntityFromClassMetadata(Exercise::class, 'e');
        $qb->select($mapping->generateSelectClause())
            ->orderBy('e.name', 'ASC')
            ->addOrderBy('e.id', 'ASC')
            ->setFirstResult(($page - 1) * $limit)
            ->setMaxResults($limit);

        $nativeQuery = $this->em->createNativeQuery($qb->getSQL(), $mapping);
        foreach ($qb->getParameters() as $name => $value) {
            $nativeQuery->setParameter($name, $value);
        }

        return ['exercises' => $nativeQuery->getResult(), 'total' => $total];
    }

    /** @return list<string> */
    private function tokens(string $query): array
    {
        $query = strtolower(strtr($query, self::DIACRITICS));
        preg_match_all('/[\p{L}\p{N}%_!]+/u', $query, $matches);
        $tokens = array_values(array_diff(array_unique($matches[0]), self::CONNECTORS));

        // "Peso muerto" is one movement, not an extra requirement for "weight".
        if (in_array('muerto', $tokens, true) || in_array('muertos', $tokens, true)
            || in_array('corporal', $tokens, true)) {
            $tokens = array_values(array_diff($tokens, ['peso']));
        }

        return $tokens;
    }

    private function foldColumn(string $column): string
    {
        $expression = 'COALESCE(e.' . $column . ", '')";
        foreach (self::DIACRITICS as $accent => $plain) {
            $expression = 'REPLACE(' . $expression . ", '" . $accent . "', '" . $plain . "')";
        }

        return 'LOWER(' . $expression . ')';
    }
}
