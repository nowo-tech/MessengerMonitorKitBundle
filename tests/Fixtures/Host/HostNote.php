<?php

declare(strict_types=1);

namespace Nowo\MessengerMonitorKitBundle\Tests\Fixtures\Host;

use Doctrine\ORM\Mapping as ORM;

/**
 * Host entity on the host's default entity manager (rule 6 kernel tests).
 */
#[ORM\Entity]
#[ORM\Table(name: 'host_note')]
class HostNote
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public ?int $id = null;

    public function __construct(
        #[ORM\Column(length: 64)]
        public string $text = '',
    ) {
    }
}
