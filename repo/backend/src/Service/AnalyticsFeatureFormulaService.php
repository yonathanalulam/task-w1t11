<?php

declare(strict_types=1);

namespace App\Service;

final class AnalyticsFeatureFormulaService
{
    public function validate(string $formulaExpression): void
    {
        $expression = trim($formulaExpression);
        if ($expression === '') {
            throw new \InvalidArgumentException('Formula expression cannot be empty.');
        }

        try {
            $this->evaluateExpression($expression, $this->formulaContext([]));
        } catch (\Throwable $e) {
            throw new \InvalidArgumentException(sprintf('Invalid formula expression syntax: %s', $e->getMessage()), 0, $e);
        }
    }

    /** @param array<string, mixed> $row */
    public function matchesRow(string $formulaExpression, array $row): bool
    {
        try {
            $result = $this->evaluateExpression(trim($formulaExpression), $this->formulaContext($row));
        } catch (\Throwable) {
            return false;
        }

        if (is_bool($result)) {
            return $result;
        }

        if (is_int($result) || is_float($result)) {
            return (float) $result > 0.0;
        }

        return false;
    }

    /** @param array<string, float> $context */
    private function evaluateExpression(string $expression, array $context): float|bool
    {
        $normalized = preg_replace('/\s+/', ' ', trim($expression));
        if (!is_string($normalized) || $normalized === '') {
            throw new \InvalidArgumentException('Expression is empty.');
        }

        foreach ($context as $name => $value) {
            $normalized = preg_replace('/\b'.preg_quote($name, '/').'\b/', (string) $value, $normalized);
        }

        if (!is_string($normalized)) {
            throw new \InvalidArgumentException('Expression normalization failed.');
        }

        if (preg_match('/[A-Za-z_$]/', $normalized) === 1) {
            throw new \InvalidArgumentException('Expression contains unknown identifiers.');
        }

        if (preg_match('/^[0-9\.\s+\-*\/()<>!=]+$/', $normalized) !== 1) {
            throw new \InvalidArgumentException('Expression contains unsupported tokens.');
        }

        [$operator, $position] = $this->findTopLevelComparisonOperator($normalized);
        if ($operator !== null && $position !== null) {
            $left = trim(substr($normalized, 0, $position));
            $right = trim(substr($normalized, $position + strlen($operator)));
            if ($left === '' || $right === '') {
                throw new \InvalidArgumentException('Comparison expression is malformed.');
            }

            $leftValue = $this->evaluateArithmeticExpression($left);
            $rightValue = $this->evaluateArithmeticExpression($right);

            return match ($operator) {
                '>' => $leftValue > $rightValue,
                '>=' => $leftValue >= $rightValue,
                '<' => $leftValue < $rightValue,
                '<=' => $leftValue <= $rightValue,
                '==' => abs($leftValue - $rightValue) < 0.000001,
                '!=' => abs($leftValue - $rightValue) >= 0.000001,
                default => throw new \InvalidArgumentException('Unsupported comparison operator.'),
            };
        }

        return $this->evaluateArithmeticExpression($normalized);
    }

    /** @return array{0: string|null, 1: int|null} */
    private function findTopLevelComparisonOperator(string $expression): array
    {
        $operators = ['>=', '<=', '==', '!=', '>', '<'];
        $depth = 0;
        $length = strlen($expression);

        for ($i = 0; $i < $length; ++$i) {
            $char = $expression[$i];
            if ($char === '(') {
                ++$depth;
                continue;
            }

            if ($char === ')') {
                --$depth;
                if ($depth < 0) {
                    throw new \InvalidArgumentException('Unbalanced parentheses in expression.');
                }
                continue;
            }

            if ($depth !== 0) {
                continue;
            }

            foreach ($operators as $operator) {
                if (substr($expression, $i, strlen($operator)) === $operator) {
                    return [$operator, $i];
                }
            }
        }

        if ($depth !== 0) {
            throw new \InvalidArgumentException('Unbalanced parentheses in expression.');
        }

        return [null, null];
    }

    private function evaluateArithmeticExpression(string $expression): float
    {
        $expr = trim($expression);
        if ($expr === '') {
            throw new \InvalidArgumentException('Arithmetic expression cannot be empty.');
        }

        if ($expr[0] === '-') {
            $expr = '0'.$expr;
        }
        $expr = str_replace('(-', '(0-', $expr);

        $tokens = $this->tokenizeArithmeticExpression($expr);
        $rpn = $this->toRpn($tokens);

        return $this->evaluateRpn($rpn);
    }

    /** @return list<string> */
    private function tokenizeArithmeticExpression(string $expression): array
    {
        $tokens = [];
        $length = strlen($expression);
        $number = '';

        for ($i = 0; $i < $length; ++$i) {
            $char = $expression[$i];

            if ($char === ' ') {
                continue;
            }

            if (ctype_digit($char) || $char === '.') {
                $number .= $char;
                continue;
            }

            if ($number !== '') {
                $tokens[] = $number;
                $number = '';
            }

            if (in_array($char, ['+', '-', '*', '/', '(', ')'], true)) {
                $tokens[] = $char;
                continue;
            }

            throw new \InvalidArgumentException('Unsupported arithmetic token.');
        }

        if ($number !== '') {
            $tokens[] = $number;
        }

        if ($tokens === []) {
            throw new \InvalidArgumentException('No arithmetic tokens found.');
        }

        return $tokens;
    }

    /** @param list<string> $tokens @return list<string> */
    private function toRpn(array $tokens): array
    {
        $output = [];
        $stack = [];

        $precedence = [
            '+' => 1,
            '-' => 1,
            '*' => 2,
            '/' => 2,
        ];

        foreach ($tokens as $token) {
            if (is_numeric($token)) {
                $output[] = $token;
                continue;
            }

            if (isset($precedence[$token])) {
                while ($stack !== []) {
                    $top = end($stack);
                    if (!is_string($top) || !isset($precedence[$top]) || $precedence[$top] < $precedence[$token]) {
                        break;
                    }
                    $output[] = array_pop($stack);
                }
                $stack[] = $token;
                continue;
            }

            if ($token === '(') {
                $stack[] = $token;
                continue;
            }

            if ($token === ')') {
                while ($stack !== [] && end($stack) !== '(') {
                    $output[] = array_pop($stack);
                }

                if ($stack === [] || end($stack) !== '(') {
                    throw new \InvalidArgumentException('Mismatched parentheses in arithmetic expression.');
                }

                array_pop($stack);
                continue;
            }
        }

        while ($stack !== []) {
            $top = array_pop($stack);
            if ($top === '(' || $top === ')') {
                throw new \InvalidArgumentException('Mismatched parentheses in arithmetic expression.');
            }
            $output[] = $top;
        }

        return $output;
    }

    /** @param list<string> $rpn */
    private function evaluateRpn(array $rpn): float
    {
        $stack = [];

        foreach ($rpn as $token) {
            if (is_numeric($token)) {
                $stack[] = (float) $token;
                continue;
            }

            if (count($stack) < 2) {
                throw new \InvalidArgumentException('Malformed arithmetic expression.');
            }

            $right = (float) array_pop($stack);
            $left = (float) array_pop($stack);

            $stack[] = match ($token) {
                '+' => $left + $right,
                '-' => $left - $right,
                '*' => $left * $right,
                '/' => abs($right) < 0.0000001 ? 0.0 : $left / $right,
                default => throw new \InvalidArgumentException('Unsupported arithmetic operator.'),
            };
        }

        if (count($stack) !== 1) {
            throw new \InvalidArgumentException('Malformed arithmetic expression result.');
        }

        return (float) $stack[0];
    }

    /** @param array<string, mixed> $row @return array<string, float> */
    private function formulaContext(array $row): array
    {
        return [
            'intakeCount' => (float) ($row['intakeCount'] ?? 0.0),
            'breachCount' => (float) ($row['breachCount'] ?? 0.0),
            'escalationCount' => (float) ($row['escalationCount'] ?? 0.0),
            'avgReviewHours' => (float) ($row['avgReviewHours'] ?? 0.0),
            'resolutionWithinSlaPct' => (float) ($row['resolutionWithinSlaPct'] ?? 0.0),
            'evidenceCompletenessPct' => (float) ($row['evidenceCompletenessPct'] ?? 0.0),
            'breachRatePct' => (float) ($row['breachRatePct'] ?? 0.0),
            'escalationRatePct' => (float) ($row['escalationRatePct'] ?? 0.0),
            'complianceScorePct' => (float) ($row['complianceScorePct'] ?? 0.0),
        ];
    }
}
