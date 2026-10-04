<?php

declare(strict_types=1);

namespace IdSign\BusinessRegisters\Adis;

use IdSign\BusinessRegisters\Exception\InvalidInput;
use IdSign\BusinessRegisters\Internal\ListElement;
use IdSign\BusinessRegisters\VatId;

/**
 * Subjects found in the VAT register, one per VAT id, in insertion order.
 *
 * Every lookup id is read with CZ as the default country, so "45274649", "CZ45274649" and
 * "cz 4527 4649" find the same entry. A VAT id is always a VatId or a string, never an int.
 *
 * @implements \IteratorAggregate<int, VatSubject>
 */
final readonly class VatSubjects implements \IteratorAggregate, \Countable
{
    /** @var array<string, VatSubject> */
    private array $byVatId;

    /**
     * @param list<VatSubject> $subjects
     *
     * @throws InvalidInput two subjects with the same VAT id
     */
    public function __construct(array $subjects)
    {
        $byVatId = [];
        foreach ($subjects as $subject) {
            $key = (string) $subject->vatId;
            if (isset($byVatId[$key])) {
                throw new InvalidInput(\sprintf('Duplicate VAT id %s in VatSubjects.', $key));
            }
            $byVatId[$key] = $subject;
        }

        $this->byVatId = $byVatId;
    }

    /**
     * @throws InvalidInput an invalid or non-Czech VAT id
     */
    public function get(VatId|string $vatId): ?VatSubject
    {
        return $this->byVatId[(string) self::key($vatId)] ?? null;
    }

    /**
     * @throws InvalidInput an invalid or non-Czech VAT id
     */
    public function has(VatId|string $vatId): bool
    {
        return isset($this->byVatId[(string) self::key($vatId)]);
    }

    /**
     * Requested VAT ids not in the collection, normalised and de-duplicated, in request order.
     *
     * @param list<VatId|string> $requested
     *
     * @return list<VatId>
     *
     * @throws InvalidInput an invalid or non-Czech VAT id, or an element that is neither a VatId nor a string
     */
    public function missing(array $requested): array
    {
        $missing = [];
        foreach ($requested as $index => $vatId) {
            $key = self::key(ListElement::idOrString($vatId, $index, VatId::class));
            if (!isset($this->byVatId[(string) $key])) {
                $missing[(string) $key] ??= $key;
            }
        }

        return array_values($missing);
    }

    /**
     * @return list<VatSubject>
     */
    public function all(): array
    {
        return array_values($this->byVatId);
    }

    /**
     * @return \ArrayIterator<int, VatSubject>
     */
    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->all());
    }

    public function count(): int
    {
        return \count($this->byVatId);
    }

    /**
     * @throws InvalidInput
     */
    private static function key(VatId|string $vatId): VatId
    {
        $vatId = $vatId instanceof VatId ? $vatId : VatId::parse($vatId, 'CZ');
        if (!$vatId->isCzech()) {
            throw new InvalidInput(\sprintf('VAT id %s is not Czech; the VAT register holds Czech VAT ids only.', $vatId));
        }

        return $vatId;
    }
}
