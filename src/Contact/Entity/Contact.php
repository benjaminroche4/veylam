<?php

declare(strict_types=1);

namespace App\Contact\Entity;

use App\Contact\Repository\ContactRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: ContactRepository::class)]
#[ORM\Table(name: 'contact')]
class Contact
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 100)]
    #[Assert\NotBlank(message: 'contact.name.not_blank')]
    #[Assert\Length(max: 100, maxMessage: 'contact.name.max_length')]
    private ?string $name = null;

    #[ORM\Column(length: 180)]
    #[Assert\NotBlank(message: 'contact.email.not_blank')]
    #[Assert\Email(message: 'contact.email.invalid')]
    #[Assert\Length(max: 180, maxMessage: 'contact.email.max_length')]
    private ?string $email = null;

    #[ORM\Column(type: Types::TEXT)]
    #[Assert\NotBlank(message: 'contact.message.not_blank')]
    #[Assert\Length(min: 10, max: 5000, minMessage: 'contact.message.min_length', maxMessage: 'contact.message.max_length')]
    private ?string $message = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(?string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(?string $email): static
    {
        $this->email = $email;

        return $this;
    }

    public function getMessage(): ?string
    {
        return $this->message;
    }

    public function setMessage(?string $message): static
    {
        $this->message = $message;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
