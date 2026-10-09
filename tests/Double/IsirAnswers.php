<?php

declare(strict_types=1);

namespace IdSign\BusinessRegisters\Tests\Double;

use IdSign\BusinessRegisters\Tests\FixtureLoader;

/**
 * Builds ISIR answers from the recorded Sberbank row for the result-limit tests.
 */
final class IsirAnswers
{
    /**
     * $proceedings distinct case numbers with $rowsEach debtor rows each.
     */
    public static function withProceedings(int $proceedings, int $rowsEach = 1): string
    {
        $xml = FixtureLoader::read('Isir/sberbank-25083325-konkurs-ongoing.xml');
        if (1 !== preg_match('~<data>.*</data>~s', $xml, $row)) {
            throw new \LogicException('The recorded Sberbank answer has no data row');
        }

        $rows = '';
        for ($i = 0; $i < $proceedings; ++$i) {
            $rows .= str_repeat(str_replace('<bcVec>12575<', '<bcVec>'.(10000 + $i).'<', $row[0]), $rowsEach);
        }

        return str_replace([$row[0], '<pocetVysledku>1<'], [$rows, '<pocetVysledku>'.($proceedings * $rowsEach).'<'], $xml);
    }
}
