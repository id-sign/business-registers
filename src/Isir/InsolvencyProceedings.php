<?php

declare(strict_types=1);

namespace IdSign\BusinessRegisters\Isir;

/**
 * Proceedings the register lists for one query, in response order.
 *
 * @implements \IteratorAggregate<int, InsolvencyProceeding>
 */
final readonly class InsolvencyProceedings implements \IteratorAggregate, \Countable
{
    /**
     * @param list<InsolvencyProceeding> $proceedings    ended proceedings included
     * @param ?\DateTimeImmutable        $synchronisedAt time the register data were synchronised, Prague local time
     *                                                   as the service reports it (verified in summer time); a
     *                                                   freshness hint, never part of a verdict
     */
    public function __construct(
        public array $proceedings,
        public ?\DateTimeImmutable $synchronisedAt,
    ) {
    }

    /**
     * @return list<InsolvencyProceeding>
     */
    public function ongoing(): array
    {
        return array_values(array_filter($this->proceedings, static fn (InsolvencyProceeding $proceeding): bool => $proceeding->isOngoing()));
    }

    public function hasOngoing(): bool
    {
        return array_any($this->proceedings, static fn (InsolvencyProceeding $proceeding): bool => $proceeding->isOngoing());
    }

    /**
     * @return \ArrayIterator<int, InsolvencyProceeding>
     */
    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->proceedings);
    }

    public function count(): int
    {
        return \count($this->proceedings);
    }
}
