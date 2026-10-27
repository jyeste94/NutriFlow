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

    // Abbreviations are expanded only as query tokens, never as arbitrary substrings.
    private const QUERY_ALIASES = ['db' => 'dumbbell', 'bb' => 'barbell', 'kb' => 'kettlebell', 'ez' => 'ez bar'];

    // Compound movements stay compound: a pájaro is not any chest fly, nor is
    // "jalón al pecho" a triceps pushdown. These phrases occur in the bundled catalogs.
    private const MOVEMENTS = [
        '@rear-fly' => ['pajaro', 'vuelo posterior', 'vuelos posteriores', 'apertura posterior', 'aperturas posteriores', 'apertura inversa', 'aperturas inversas', 'reverse fly', 'reverse machine fly', 'rear delt fly', 'rear delt raise', 'rear lateral raise'],
        '@lat-pulldown' => ['jalon al pecho', 'jalones al pecho', 'lat pulldown', 'lat pull down', 'lat pull-down'],
        '@bench-press' => ['bench press', 'chest press', 'incline dumbbell press', 'press de banca', 'press banca', 'press de banco', 'press banco', 'press de pecho', 'press pecho'],
        '@shoulder-press' => ['military press', 'shoulder press', 'press de hombros', 'press hombros', 'press militar'],
        '@leg-curl' => ['leg curl', 'hamstring curl', 'curl de pierna', 'curls de pierna', 'curl femoral', 'curl isquios', 'curl isquiotibiales'],
        '@lateral-raise' => ['lateral raise', 'elevacion lateral', 'elevaciones laterales', 'laterales'],
        '@skullcrusher' => ['skullcrusher', 'skull crusher', 'press frances'],
        '@hip-thrust' => ['hip thrust', 'empuje de cadera', 'empuje de caderas'],
    ];

    private const SYNONYMS = [
        ['banca', 'banco', 'bench'],
        ['mancuerna', 'mancuernas', 'dumbbell', 'dumbbells', 'db'],
        ['barra', 'barras', 'barbell', 'barbells', 'bb'],
        ['pesa rusa', 'pesas rusas', 'kettlebell', 'kettlebells', 'kb'],
        ['ez bar', 'ez-bar', 'e-z curl bar', 'barra z', 'barra ez', 'ez'],
        ['remo', 'remos', 'row', 'rows', 'rowing'],
        ['sentadilla', 'sentadillas', 'squat', 'squats'],
        ['muerto', 'muertos', 'deadlift', 'deadlifts'],
        ['peso', 'weight', 'weights'],
        ['plano', 'plana', 'flat'],
        ['inclinado', 'inclinada', 'inclinados', 'inclinadas', 'incline', 'inclined'],
        ['declinado', 'declinada', 'declinados', 'declinadas', 'decline', 'declined'],
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
        ['martillo', 'hammer'],
        ['neutro', 'neutra', 'neutral'],
        ['tumbado', 'tumbada', 'acostado', 'acostada', 'lying'],
    ];

    // An instruction may suggest another implement or angle; that is not the
    // exercise's identity. Flat remains descriptive for legacy bilingual names.
    private const QUALIFIERS = [
        'dumbbell', 'barbell', 'kettlebell', 'ez bar', 'cable', 'machine', 'bodyweight',
        'incline', 'decline', 'romanian', 'deadlift', 'hammer', 'neutral',
        'wide', 'close', 'seated', 'lying',
    ];

    // A movement named in instructions (e.g. "press your arm against the bench")
    // does not turn a curl into a press.
    private const NAME_MOVEMENTS = [
        'press', 'row', 'squat', 'deadlift', 'pulldown', 'pullup', 'pushup',
        'fly', 'raise', 'extension', 'curl',
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
        if ($tokens === []) {
            return ['exercises' => [], 'total' => 0];
        }

        $connection = $this->em->getConnection();
        $fields = ['name', 'name_en', 'name_es', 'source_name', 'equipment', 'description'];
        $folded = [];
        foreach ($fields as $field) {
            $expression = $this->foldColumn($field);
            if ($field === 'equipment') {
                // Hevy's broad "machine" category also covers cables. Refine only
                // when an official bilingual title explicitly supplies the implement.
                $officialNames = $connection->getDatabasePlatform()->getConcatExpression("COALESCE(e.name_en, '')", "' '", "COALESCE(e.name_es, '')");
                $officialWords = $connection->getDatabasePlatform()->getConcatExpression("' '", $this->wordsColumn("LOWER($officialNames)"), "' '");
                $cableTitle = "($officialWords LIKE '% cable %' OR $officialWords LIKE '% cables %' OR $officialWords LIKE '% polea %' OR $officialWords LIKE '% poleas %')";
                $expression = "(CASE WHEN $expression IN ('machine', 'machines', 'maquina', 'maquinas') AND $cableTitle THEN 'cable' ELSE $expression END)";
            }
            $folded[] = $expression . ' AS search_' . $field;
        }
        // Project normalization once instead of embedding nested REPLACEs in
        // every synonym and ranking clause, keeping generated SQL bounded.
        $normalizedSource = '(SELECT e.*, ' . implode(', ', $folded) . ' FROM exercises e)';
        $qb = $connection->createQueryBuilder();
        $nameFields = array_slice($fields, 0, 4);
        $nameColumns = array_map(static fn (string $field) => 'e.search_' . $field, $nameFields);
        $scores = [];
        $wordFields = [];

        foreach ($tokens as $index => $token) {
            $movement = isset(self::MOVEMENTS[$token]);
            $alternatives = self::MOVEMENTS[$token] ?? [$token];
            if (!$movement) {
                foreach (self::SYNONYMS as $synonyms) {
                    if (in_array($token, $synonyms, true)) {
                        $alternatives = $synonyms;
                        break;
                    }
                }
            }

            $matches = $nameMatches = $equipmentMatches = [];
            $nameOnly = $movement || array_intersect($alternatives, self::NAME_MOVEMENTS) !== [];
            $columns = $nameOnly ? $nameFields
                : (array_intersect($alternatives, self::QUALIFIERS) !== [] ? array_slice($fields, 0, 5) : $fields);
            foreach ($alternatives as $alternativeIndex => $alternative) {
                $parameter = 'token_' . $index . '_' . $alternativeIndex;
                $abbreviation = isset(self::QUERY_ALIASES[$alternative]);
                foreach ($columns as $field) {
                    $column = 'e.search_' . $field;
                    if ($abbreviation || $nameOnly || str_contains($alternative, ' ')) {
                        $wordFields[$field] = true;
                        $column = 'e.search_words_' . $field;
                    }
                    $match = $column . ' LIKE :' . $parameter . " ESCAPE '!'";
                    $matches[] = $match;
                    if (in_array($field, $nameFields, true)) {
                        $nameMatches[] = $match;
                    } elseif ($field === 'equipment') {
                        $equipmentMatches[] = $match;
                    }
                }
                $literal = str_contains($alternative, ' ') ? str_replace('-', ' ', $alternative) : $alternative;
                $qb->setParameter($parameter, '%' . ($abbreviation || $nameOnly ? ' ' : '')
                    . $this->escapeLike($literal) . ($abbreviation ? ' ' : '') . '%');
            }
            $qb->andWhere('(' . implode(' OR ', $matches) . ')');
            // A name match beats incidental instructions; explicit equipment in
            // a name also beats broad source metadata (e.g. Hevy cable=machine).
            $scores[] = '(CASE WHEN (' . implode(' OR ', $nameMatches) . ') THEN 100'
                . ($equipmentMatches === [] ? '' : ' WHEN (' . implode(' OR ', $equipmentMatches) . ') THEN 10')
                . ' ELSE 0 END)';
        }
        if ($wordFields !== []) {
            $wordColumns = [];
            foreach (array_keys($wordFields) as $field) {
                $words = $connection->getDatabasePlatform()->getConcatExpression("' '", $this->wordsColumn('e.search_' . $field), "' '");
                $wordColumns[] = $words . ' AS search_words_' . $field;
            }
            $normalizedSource = '(SELECT e.*, ' . implode(', ', $wordColumns) . ' FROM ' . $normalizedSource . ' e)';
        }
        $qb->from($normalizedSource, 'e');

        $total = (int) (clone $qb)->select('COUNT(e.id)')->executeQuery()->fetchOne();
        $mapping = new ResultSetMappingBuilder($this->em);
        $mapping->addRootEntityFromClassMetadata(Exercise::class, 'e');
        $exact = [];
        foreach ($nameColumns as $column) {
            $exact[] = $column . ' = :exact_query';
        }
        $foldedQuery = strtolower(strtr(trim($query), self::DIACRITICS));
        $qb->setParameter('exact_query', $foldedQuery);
        $qb->select($mapping->generateSelectClause())
            ->orderBy('(CASE WHEN ' . implode(' OR ', $exact) . ' THEN 1 ELSE 0 END)', 'DESC')
            ->addOrderBy('(' . implode(' + ', $scores) . ')', 'DESC')
            // Fewer additional qualifiers favor the regular movement over a
            // Hammer Grip/one-arm/etc. variant, without removing any entity.
            // A raw substring bonus would unfairly favor catalog titles using DB
            // over equivalent official names spelling out Dumbbell.
            ->addOrderBy("LENGTH(COALESCE(NULLIF(e.name_en, ''), NULLIF(e.source_name, ''), e.name))", 'ASC')
            ->addOrderBy('e.name', 'ASC')
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
        foreach ($matches[0] as $index => $token) {
            $matches[0][$index] = self::QUERY_ALIASES[$token] ?? $token;
        }
        $tokens = array_values(array_diff(array_unique($matches[0]), self::CONNECTORS));
        if (count($tokens) > self::MAX_TERMS) {
            throw new \InvalidArgumentException('La búsqueda admite como máximo 20 términos distintos.');
        }

        // The compound consumes only its own words; equipment, angle and grip
        // remain separate AND requirements. Accept reordered gym shorthand too.
        $compounds = [
            [['pull', 'down'], 'pulldown'],
            [['pull', 'up'], 'pullup'],
            [['push', 'up'], 'pushup'],
            [['pesa', 'rusa'], 'kettlebell'],
            [['pesas', 'rusas'], 'kettlebell'],
            [['barra', 'z'], 'ez bar'],
            [['jalon', 'pecho'], '@lat-pulldown'],
            [['jalones', 'pecho'], '@lat-pulldown'],
            [['lat', 'pulldown'], '@lat-pulldown'],
            [['press', 'banca'], '@bench-press'],
            [['press', 'banco'], '@bench-press'],
            [['bench', 'press'], '@bench-press'],
            [['press', 'pecho'], '@bench-press'],
            [['chest', 'press'], '@bench-press'],
            [['press', 'militar'], '@shoulder-press'],
            [['military', 'press'], '@shoulder-press'],
            [['press', 'hombro'], '@shoulder-press'],
            [['press', 'hombros'], '@shoulder-press'],
            [['shoulder', 'press'], '@shoulder-press'],
            [['curl', 'femoral'], '@leg-curl'],
            [['curl', 'isquios'], '@leg-curl'],
            [['curl', 'isquiotibiales'], '@leg-curl'],
            [['press', 'frances'], '@skullcrusher'],
            [['skull', 'crusher'], '@skullcrusher'],
            [['hip', 'thrust'], '@hip-thrust'],
            [['reverse', 'fly'], '@rear-fly'],
            [['reverse', 'flyes'], '@rear-fly'],
            [['reverse', 'flies'], '@rear-fly'],
            [['apertura', 'posterior'], '@rear-fly'],
            [['aperturas', 'posteriores'], '@rear-fly'],
            [['apertura', 'inversa'], '@rear-fly'],
            [['aperturas', 'inversas'], '@rear-fly'],
            [['vuelo', 'posterior'], '@rear-fly'],
            [['vuelos', 'posteriores'], '@rear-fly'],
        ];
        foreach ($compounds as [$words, $movement]) {
            if (array_diff($words, $tokens) === []) {
                $tokens = array_values(array_diff($tokens, $words));
                $tokens[] = $movement;
            }
        }
        if (in_array('@rear-fly', $tokens, true)) {
            $tokens = array_values(array_diff($tokens, ['rear', 'delt', 'delts']));
        }
        foreach ($tokens as &$token) {
            $token = match ($token) {
                'pajaro', 'pajaros' => '@rear-fly',
                'laterales' => '@lateral-raise',
                'skullcrusher' => '@skullcrusher',
                default => $token,
            };
        }
        unset($token);

        // "Peso muerto" is one movement, not an extra requirement for "weight".
        if (in_array('muerto', $tokens, true) || in_array('muertos', $tokens, true)
            || in_array('corporal', $tokens, true)) {
            $tokens = array_values(array_diff($tokens, ['peso']));
        }

        return array_values(array_unique($tokens));
    }

    private function escapeLike(string $value): string
    {
        return strtr($value, ['!' => '!!', '%' => '!%', '_' => '!_']);
    }

    private function wordsColumn(string $expression): string
    {
        foreach (['(', ')', '[', ']', '-', '/', '.', ',', ':', ';', "\t", "\r", "\n"] as $separator) {
            $expression = "REPLACE($expression, '$separator', ' ')";
        }
        // Collapse the small punctuation/spacing runs present in catalog titles.
        for ($iteration = 0; $iteration < 3; ++$iteration) {
            $expression = "REPLACE($expression, '  ', ' ')";
        }

        return "TRIM($expression)";
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
