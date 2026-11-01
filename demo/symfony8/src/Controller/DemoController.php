<?php

declare(strict_types=1);

namespace App\Controller;

use Nowo\MessengerMonitorKitBundle\Queue\QueueDepth;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Demo landing page: what the kit adds, the live queue depth (the service hosts reuse for metrics / health) and
 * links to the monitor.
 */
final class DemoController extends AbstractController
{
    #[Route('/', name: 'demo_home')]
    public function home(QueueDepth $queueDepth): Response
    {
        return $this->render('home.html.twig', ['depth' => $queueDepth->report()]);
    }
}
