<?php

declare(strict_types=1);

namespace TacticMedia\ScimServerBundle\Tests\Doctrine\Orm\Fixtures;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use TacticMedia\ScimServerBundle\Mapping\ScimAttribute;
use TacticMedia\ScimServerBundle\Mapping\ScimResource;

/**
 * A ManyToMany element collection whose elements have random string ids.
 */
#[ORM\Entity]
#[ORM\Table(name: 'keyset_crew')]
#[ScimResource('User', idPrefix: 'c')]
class Crew
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public ?int $id = null;

    /** @var Collection<int, CrewEmail> */
    #[ORM\ManyToMany(targetEntity: CrewEmail::class, cascade: ['persist'])]
    #[ORM\JoinTable(name: 'keyset_crew_email')]
    #[ORM\OrderBy(['id' => 'ASC'])]
    #[ScimAttribute('emails', elementClass: CrewEmail::class)]
    private Collection $emails;

    public function __construct(
        #[ORM\Column(length: 20)]
        #[ScimAttribute('userName')]
        public string $userName,
    ) {
        $this->emails = new ArrayCollection();
    }

    /**
     * @return Collection<int, CrewEmail>
     */
    public function getEmails(): Collection
    {
        return $this->emails;
    }

    public function addEmail(CrewEmail $email): void
    {
        $this->emails->add($email);
    }

    public function removeEmail(CrewEmail $email): void
    {
        $this->emails->removeElement($email);
    }
}
