<?php

namespace FpDbTest;

use InvalidArgumentException;
use mysqli;

class Database implements DatabaseInterface
{
    private mysqli $mysqli;

    private const /*string*/ UNEXPECTED_VARIABLE_PATTERN = 'Unexpected variable for modifier `%s` -> passed `%s`';
    private const /*mixed*/ SKIP_VALUE = null;

    public function __construct(mysqli $mysqli)
    {
        $this->mysqli = $mysqli;
    }

    public function buildQuery(string $query, array $args = []): string
    {
        $regex = '/({.*?}|\?d|\?f|\?a|\?#|\?)/';
        $parts = preg_split($regex, $query, -1, PREG_SPLIT_DELIM_CAPTURE);
        if (($expectedCount = intdiv(count($parts), 2)) !== ($passedCount = count($args))) {
            throw new InvalidArgumentException(sprintf("query expect %d arguments but %d passed.", $expectedCount, $passedCount));
        }

        foreach ($args as $i => $arg) {
            $part = &$parts[$i * 2 + 1];
            if ($part[0] == '?') {
                $part = $this->parseVariable($part, $arg);
            } elseif ($part[0] == '{') {
                if ($arg === self::SKIP_VALUE) {
                    $part = '';
                    continue;
                }
                $modPos = strpos($part, '?');
                $mod = $part[$modPos + 1];
                if (in_array($mod, ['d', 'f', 'a', '#'])) {
                    $mod = '?' . $mod;
                }
                $variable = $this->parseVariable($mod, $arg);
                $part = substr(substr_replace($part, $variable, $modPos, strlen($mod)), 1, -1);
            } else {
                throw new InvalidArgumentException('Probably invalid query string.');
            }
        }
        return (implode('', $parts));
    }

    function parseVariable(string $modifier, $variable): float|int|string
    {
        return match ($modifier) {
            '?d' => $this->parseInt($variable),
            '?f' => $this->parseFloat($variable),
            '?#' => $this->parseIdentifiers($variable),
            '?a' => $this->parseArray($variable),
            '?' => $this->parseBase($variable),
            default => throw new InvalidArgumentException("Unexpected modifier: `$modifier`"),
        };
    }

    function parseInt($variable): int
    {
        return match (true) {
            is_null($variable) => 'NULL',
            is_int($variable) => $variable,
            is_bool($variable) => intval($variable),
            ($result = intval($variable)) == $variable => $result,
            default => throw new InvalidArgumentException(sprintf(self::UNEXPECTED_VARIABLE_PATTERN, '?d', $variable)),
        };
    }

    function parseFloat($variable): float
    {
        return match (true) {
            is_null($variable) => 'NULL',
            is_float($variable) => $variable,
            is_bool($variable), is_numeric($variable) => intval($variable),
            default => throw new InvalidArgumentException(sprintf(self::UNEXPECTED_VARIABLE_PATTERN, '?f', $variable)),
        };
    }

    function parseIdentifiers($variable): string
    {
        if (!is_string($variable) && (!is_array($variable) || !array_is_list($variable))) {
            throw new InvalidArgumentException(sprintf(self::UNEXPECTED_VARIABLE_PATTERN, '?#', $variable));
        }
        if (is_array($variable)) {
            $variable = implode('`, `', $variable);
        }
        return "`$variable`";
    }

    function parseArray($variable): string
    {
        if (!is_array($variable)) {
            throw new InvalidArgumentException(sprintf(self::UNEXPECTED_VARIABLE_PATTERN, '?a', $variable));
        }
        try {
            if (array_is_list($variable)) {
                $result = array_map(function ($v) {
                    return $this->parseBase($v);
                }, $variable);
            } else {
                $result = array_map(function ($key, $value) {
                    return sprintf("`$key` = %s", $this->parseBase($value));
                }, array_keys($variable), array_values($variable));
            }
        } catch (InvalidArgumentException $e) {
            throw new InvalidArgumentException('Only scalar values supported in array for  `?a` modifier.', 0, $e);
        }

        return implode(', ', $result);
    }

    function parseBase($variable): float|int|string
    {
        return match (true) {
            is_string($variable) => sprintf('\'%s\'', $variable),
            is_int($variable), is_float($variable) => $variable,
            is_bool($variable) => $variable ? 1 : 0,
            is_null($variable) => 'NULL',
            default => throw new InvalidArgumentException(sprintf(self::UNEXPECTED_VARIABLE_PATTERN, '?', $variable)),
        };
    }

    public function skip(): mixed
    {
        return self::SKIP_VALUE;
    }
}
