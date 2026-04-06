<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\PractitionerProfileRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: PractitionerProfileRepository::class)]
#[ORM\Table(name: 'practitioner_profiles')]
#[ORM\UniqueConstraint(name: 'uniq_practitioner_user', columns: ['user_id'])]
class PractitionerProfile
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\OneToOne]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(name: 'lawyer_full_name', length: 180)]
    private string $lawyerFullName;

    #[ORM\Column(name: 'firm_name', length: 180)]
    private string $firmName;

    #[ORM\Column(name: 'bar_jurisdiction', length: 120)]
    private string $barJurisdiction;

    #[ORM\Column(name: 'license_key_id', length: 80)]
    private string $licenseKeyId;

    #[ORM\Column(name: 'license_nonce', length: 255)]
    private string $licenseNonce;

    #[ORM\Column(name: 'license_ciphertext', length: 4096)]
    private string $licenseCiphertext;

    #[ORM\Column(name: 'license_auth_tag', length: 255)]
    private string $licenseAuthTag;

    #[ORM\Column(name: 'license_number_masked', length: 64)]
    private string $licenseNumberMasked;

    #[ORM\Column(name: 'created_at_utc')]
    private \DateTimeImmutable $createdAtUtc;

    #[ORM\Column(name: 'updated_at_utc')]
    private \DateTimeImmutable $updatedAtUtc;

    /** @param array{key_id: string, nonce: string, ciphertext: string, auth_tag: string} $encryptedLicense */
    public function __construct(
        User $user,
        string $lawyerFullName,
        string $firmName,
        string $barJurisdiction,
        array $encryptedLicense,
        string $licenseNumberMasked,
    ) {
        $this->user = $user;
        $this->lawyerFullName = $lawyerFullName;
        $this->firmName = $firmName;
        $this->barJurisdiction = $barJurisdiction;

        $this->licenseKeyId = $encryptedLicense['key_id'];
        $this->licenseNonce = $encryptedLicense['nonce'];
        $this->licenseCiphertext = $encryptedLicense['ciphertext'];
        $this->licenseAuthTag = $encryptedLicense['auth_tag'];
        $this->licenseNumberMasked = $licenseNumberMasked;

        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $this->createdAtUtc = $now;
        $this->updatedAtUtc = $now;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getLawyerFullName(): string
    {
        return $this->lawyerFullName;
    }

    public function getFirmName(): string
    {
        return $this->firmName;
    }

    public function getBarJurisdiction(): string
    {
        return $this->barJurisdiction;
    }

    public function getLicenseNumberMasked(): string
    {
        return $this->licenseNumberMasked;
    }

    public function getUpdatedAtUtc(): \DateTimeImmutable
    {
        return $this->updatedAtUtc;
    }

    /** @return array{key_id: string, nonce: string, ciphertext: string, auth_tag: string} */
    public function encryptedLicensePayload(): array
    {
        return [
            'key_id' => $this->licenseKeyId,
            'nonce' => $this->licenseNonce,
            'ciphertext' => $this->licenseCiphertext,
            'auth_tag' => $this->licenseAuthTag,
        ];
    }

    /** @param array{key_id: string, nonce: string, ciphertext: string, auth_tag: string} $encryptedLicense */
    public function update(
        string $lawyerFullName,
        string $firmName,
        string $barJurisdiction,
        array $encryptedLicense,
        string $licenseNumberMasked,
    ): void {
        $this->lawyerFullName = $lawyerFullName;
        $this->firmName = $firmName;
        $this->barJurisdiction = $barJurisdiction;

        $this->licenseKeyId = $encryptedLicense['key_id'];
        $this->licenseNonce = $encryptedLicense['nonce'];
        $this->licenseCiphertext = $encryptedLicense['ciphertext'];
        $this->licenseAuthTag = $encryptedLicense['auth_tag'];
        $this->licenseNumberMasked = $licenseNumberMasked;

        $this->touch();
    }

    private function touch(): void
    {
        $this->updatedAtUtc = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }
}
