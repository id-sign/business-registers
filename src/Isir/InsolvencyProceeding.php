<?php

declare(strict_types=1);

namespace IdSign\BusinessRegisters\Isir;

use IdSign\BusinessRegisters\Address;
use IdSign\BusinessRegisters\CompanyId;

/**
 * One debtor row of one insolvency proceeding as the register lists it (one `data` element). Rows sharing a reference
 * can be co-debtors (with the co-debtor's personal data) or the same debtor listed twice (two addresses).
 *
 * Carries personal data of natural persons (birth number, birth date, name, residence; § 420 of Act 182/2006 Sb.).
 */
final readonly class InsolvencyProceeding
{
    /** States in which the proceeding has ended, compared exactly as received. */
    private const array ENDED_STATES = ['ODSKRTNUTA', 'PRAVOMOCNA', 'VYRIZENA', 'MYLNÝ ZÁP.'];

    /**
     * @param ?CompanyId          $companyId               ISIR ic; the check digit is not verified
     * @param ?string             $birthNumber             ISIR rc, exactly as received
     * @param int                 $senate                  ISIR cisloSenatu
     * @param string              $caseType                ISIR druhVec, observed "INS"
     * @param int                 $caseNumber              ISIR bcVec
     * @param int                 $year                    ISIR rocnik
     * @param ?string             $court                   ISIR nazevOrganizace
     * @param ?\DateTimeImmutable $bornOn                  ISIR datumNarozeni
     * @param ?string             $name                    ISIR nazevOsoby: surname of a natural person or name of a legal person
     * @param ?string             $addressKind             ISIR druhAdresy, observed "SÍDLO FY", "SÍDLO ORG.", "TRVALÁ"
     * @param ?string             $stateCode               ISIR druhStavKonkursu, observed NEVYRIZENA, MORATORIUM, ÚPADEK,
     *                                                     KONKURS, REORGANIZ, ODDLUŽENÍ, ZRUŠENO VS, OBZIVLA, PRAVOMOCNA,
     *                                                     ODSKRTNUTA, VYRIZENA, MYLNÝ ZÁP.; any other value may appear
     * @param ?string             $detailUrl               ISIR urlDetailRizeni, the public detail page of the proceeding
     * @param bool                $otherDebtorInProceeding ISIR dalsiDluznikVRizeni as received; its meaning is undocumented and
     *                                                     varies by query, so it is no reliable "has co-debtors" answer
     * @param ?\DateTimeImmutable $insolvencyDeclaredOn    ISIR datumPmZahajeniUpadku: decision on insolvency took legal force;
     *                                                     can be null although insolvency was declared (older proceedings),
     *                                                     so stateCode decides
     * @param ?\DateTimeImmutable $endedOn                 ISIR datumPmUkonceniUpadku: end of the proceeding took legal force;
     *                                                     may be set without insolvencyDeclaredOn
     */
    public function __construct(
        public ?CompanyId $companyId,
        public ?string $birthNumber,
        public int $senate,
        public string $caseType,
        public int $caseNumber,
        public int $year,
        public ?string $court,
        public ?\DateTimeImmutable $bornOn,
        public ?string $titleBefore,
        public ?string $titleAfter,
        public ?string $firstName,
        public ?string $name,
        public ?string $addressKind,
        public ?Address $address,
        public ?string $stateCode,
        public ?string $detailUrl,
        public bool $otherDebtorInProceeding,
        public ?\DateTimeImmutable $insolvencyDeclaredOn,
        public ?\DateTimeImmutable $endedOn,
    ) {
    }

    /**
     * Case reference such as "95 INS 12575/2022".
     */
    public function reference(): string
    {
        return \sprintf('%d %s %d/%d', $this->senate, $this->caseType, $this->caseNumber, $this->year);
    }

    /**
     * No end date and no ended state; a missing or unknown state counts as ongoing.
     */
    public function isOngoing(): bool
    {
        return null === $this->endedOn && !\in_array($this->stateCode, self::ENDED_STATES, true);
    }
}
