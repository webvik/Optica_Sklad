<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;

final class MobileController extends AbstractController
{
    public function __construct(
        #[Autowire(env: 'bool:BETA_WELCOME_ENABLED')] private readonly bool $betaWelcomeEnabled,
        #[Autowire(param: 'kernel.project_dir')] private readonly string $projectDir,
    ) {
    }

    #[Route('/mobil', name: 'app_mobile_info', methods: ['GET'])]
    public function info(): Response
    {
        return $this->render('mobile/android.html.twig');
    }

    /**
     * Na beta: informační stránka. Na produkci: soubor APK (pokud existuje v public/download/).
     * Přímý soubor v public/ má přednost u web serveru; tato route pokrývá beta bez APK na disku.
     */
    #[Route('/download/optica-sklad.apk', name: 'app_mobile_apk_download', methods: ['GET'])]
    public function apkDownload(): Response
    {
        if ($this->betaWelcomeEnabled) {
            return $this->render('mobile/apk_prod_only.html.twig');
        }

        $path = $this->projectDir.'/public/download/optica-sklad.apk';
        if (!is_readable($path)) {
            throw $this->createNotFoundException('APK soubor na serveru chybí.');
        }

        $response = new BinaryFileResponse($path);
        $response->setContentDisposition(
            ResponseHeaderBag::DISPOSITION_ATTACHMENT,
            'optica-sklad.apk',
        );

        return $response;
    }
}

