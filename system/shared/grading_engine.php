<?php
/**
 * CenLearn LMS — Centralized Grading Engine
 *
 * Standardization Pipeline:
 * RAW SCORE -> TRANSMUTED GRADE -> COMPONENT WEIGHT -> FINAL GRADE
 *
 * Transmutation Formula:
 * Transmuted Grade = (Raw Score / Total Items) * 50 + 50
 *
 * Standard Component Weights:
 * - Performance Task: 20% (0.20)
 * - Exam:             40% (0.40)
 * - Quiz:             20% (0.20)
 * - Deportment:       10% (0.10)
 * - Attendance:       10% (0.10)
 * Total:             100%
 */

class GradingEngine {

    const DEFAULT_WEIGHTS = [
        'performance_pct' => 20,
        'exam_pct'        => 40,
        'written_pct'     => 20, // Quiz
        'deportment_pct'  => 10,
        'attendance_pct'  => 10,
        'midterm_weight'  => 40,
        'final_weight'    => 60,
        'base_grade'      => 50
    ];

    /**
     * Calculate Transmuted Grade from Raw Score and Total Items.
     * Formula: (Raw Score / Total Items) * 50 + 50
     *
     * @param float|int|null $rawScore
     * @param float|int $totalItems
     * @return float|null
     */
    public static function calculateTransmutedGrade($rawScore, $totalItems) {
        if ($rawScore === null || $totalItems <= 0) {
            return null;
        }
        $raw = max(0, floatval($rawScore));
        $total = floatval($totalItems);
        $transmuted = ($raw / $total) * 50 + 50;
        return round($transmuted, 2);
    }

    /**
     * Calculate Weighted Component Contribution.
     * Formula: Transmuted Grade * Component Weight (decimal)
     *
     * @param float|null $transmutedGrade
     * @param float|int $weightPct (e.g. 20 for 20%)
     * @return float
     */
    public static function calculateWeightedContribution($transmutedGrade, $weightPct) {
        if ($transmutedGrade === null) {
            return 0.00;
        }
        $decimalWeight = floatval($weightPct) / 100.0;
        return round(floatval($transmutedGrade) * $decimalWeight, 2);
    }

    /**
     * Validate configured weights total exactly 100%.
     *
     * @param array $weights
     * @return array ['valid' => bool, 'sum' => float, 'error' => string|null]
     */
    public static function validateWeights($weights) {
        $perf = floatval($weights['performance_pct'] ?? self::DEFAULT_WEIGHTS['performance_pct']);
        $exam = floatval($weights['exam_pct']        ?? self::DEFAULT_WEIGHTS['exam_pct']);
        $quiz = floatval($weights['written_pct']     ?? self::DEFAULT_WEIGHTS['written_pct']);
        $dep  = floatval($weights['deportment_pct']  ?? self::DEFAULT_WEIGHTS['deportment_pct']);
        $att  = floatval($weights['attendance_pct']  ?? self::DEFAULT_WEIGHTS['attendance_pct']);

        $extraSum = 0;
        if (!empty($weights['extra_weights'])) {
            $extras = is_array($weights['extra_weights']) ? $weights['extra_weights'] : json_decode($weights['extra_weights'], true);
            if (is_array($extras)) {
                $extraSum = array_sum(array_column($extras, 'pct'));
            }
        }

        $sum = round($perf + $exam + $quiz + $dep + $att + $extraSum, 2);
        if ($sum !== 100.00 && $sum !== 100) {
            return [
                'valid' => false,
                'sum'   => $sum,
                'error' => "Weights total {$sum}%, but must equal exactly 100% (Performance: {$perf}%, Exam: {$exam}%, Quiz: {$quiz}%, Deportment: {$dep}%, Attendance: {$att}%)."
            ];
        }
        return ['valid' => true, 'sum' => 100, 'error' => null];
    }

    /**
     * Compute Student Grade for a term using standardized pipeline:
     * RAW SCORE -> TRANSMUTED GRADE -> COMPONENT WEIGHT -> FINAL GRADE
     *
     * @param string $studentCode
     * @param array $colsByComp
     * @param array $scores
     * @param array $weights
     * @return array
     */
    public static function computeStudentGrade($studentCode, $colsByComp, $scores, $weights = []) {
        // Merge with standardized default weights
        $w = array_merge(self::DEFAULT_WEIGHTS, $weights);
        $val = self::validateWeights($w);

        $components = [
            'performance' => ['key' => 'performance_pct', 'weight' => floatval($w['performance_pct'])],
            'exam'        => ['key' => 'exam_pct',        'weight' => floatval($w['exam_pct'])],
            'written'     => ['key' => 'written_pct',     'weight' => floatval($w['written_pct'])], // Quiz
            'deportment'  => ['key' => 'deportment_pct',  'weight' => floatval($w['deportment_pct'])],
            'attendance'  => ['key' => 'attendance_pct',  'weight' => floatval($w['attendance_pct'])],
        ];

        $rawScores   = [];
        $totalItems  = [];
        $transmuted  = [];
        $weighted    = [];
        $hasAnyScore = false;

        // 1. Process standard assessment components (written/quiz, performance, exam)
        foreach (['written', 'performance', 'exam'] as $comp) {
            $cols = $colsByComp[$comp] ?? [];
            $regCols = array_filter($cols, fn($c) => empty($c['session_id']) && empty($c['is_f2f']));

            if (empty($regCols)) {
                $rawScores[$comp]  = null;
                $totalItems[$comp] = 0;
                $transmuted[$comp] = null;
                $weighted[$comp]   = 0.00;
            } else {
                $compRaw = 0;
                $compMax = 0;
                $compHas = false;

                foreach ($regCols as $col) {
                    $colId = $col['id'];
                    $colMax = floatval($col['max_score'] ?? 0);
                    $compMax += $colMax;

                    $sc = $scores[$colId][$studentCode] ?? null;
                    if ($sc !== null && $sc !== '') {
                        $compRaw += floatval($sc);
                        $compHas = true;
                        $hasAnyScore = true;
                    }
                }

                $rawScores[$comp]  = $compRaw;
                $totalItems[$comp] = $compMax;

                if ($compHas && $compMax > 0) {
                    $transmuted[$comp] = self::calculateTransmutedGrade($compRaw, $compMax);
                    $weighted[$comp]   = self::calculateWeightedContribution($transmuted[$comp], $components[$comp]['weight']);
                } else {
                    $transmuted[$comp] = null;
                    $weighted[$comp]   = 0.00;
                }
            }
        }

        // 2. Process Deportment
        $depCols = $colsByComp['deportment'] ?? [];
        $regDepCols = array_filter($depCols, fn($c) => empty($c['session_id']) && empty($c['is_f2f']));

        if (empty($regDepCols)) {
            $rawScores['deportment']  = null;
            $totalItems['deportment'] = 0;
            $transmuted['deportment'] = null;
            $weighted['deportment']   = 0.00;
        } else {
            $depRaw = 0;
            $depMax = 0;
            $depHas = false;

            foreach ($regDepCols as $col) {
                $colId = $col['id'];
                $colMax = floatval($col['max_score'] ?? 10);
                $depMax += $colMax;

                $sc = $scores[$colId][$studentCode] ?? null;
                if ($sc !== null && $sc !== '') {
                    $depRaw += floatval($sc);
                    $depHas = true;
                    $hasAnyScore = true;
                }
            }

            $rawScores['deportment']  = $depRaw;
            $totalItems['deportment'] = $depMax;

            if ($depHas && $depMax > 0) {
                $transmuted['deportment'] = self::calculateTransmutedGrade($depRaw, $depMax);
                $weighted['deportment']   = self::calculateWeightedContribution($transmuted['deportment'], $components['deportment']['weight']);
            } else {
                $transmuted['deportment'] = null;
                $weighted['deportment']   = 0.00;
            }
        }

        // 3. Process Attendance
        // Denominator includes all conducted attendance sessions in term (each session max = 2.00)
        $attCols = $colsByComp['attendance'] ?? [];

        if (empty($attCols)) {
            $rawScores['attendance']  = null;
            $totalItems['attendance'] = 0;
            $transmuted['attendance'] = null;
            $weighted['attendance']   = 0.00;
        } else {
            $attRaw = 0;
            $attMax = 0;
            $attHas = false;

            foreach ($attCols as $col) {
                $colId = $col['id'];
                $colMax = floatval($col['max_score'] ?? 2.00);
                $attMax += $colMax;

                $sc = $scores[$colId][$studentCode] ?? null;
                if ($sc !== null && $sc !== '') {
                    $attRaw += floatval($sc);
                    $attHas = true;
                    $hasAnyScore = true;
                }
            }

            $rawScores['attendance']  = $attRaw;
            $totalItems['attendance'] = $attMax;

            if ($attMax > 0) {
                // If sessions took place, calculate transmuted grade from earned score
                $transmuted['attendance'] = self::calculateTransmutedGrade($attRaw, $attMax);
                $weighted['attendance']   = self::calculateWeightedContribution($transmuted['attendance'], $components['attendance']['weight']);
            } else {
                $transmuted['attendance'] = null;
                $weighted['attendance']   = 0.00;
            }
        }

        // 4. Calculate Final Grade = Sum of all weighted contributions
        $finalGrade = null;
        if ($hasAnyScore) {
            $finalGrade = round(array_sum($weighted), 2);
        }

        return [
            'raw'         => $rawScores,
            'total_items' => $totalItems,
            'transmuted'  => $transmuted,
            'components'  => $transmuted, // compatibility alias for pages reading components
            'weighted'    => $weighted,
            'final'       => $finalGrade,
            'weights'     => $w,
            'validation'  => $val
        ];
    }
}
