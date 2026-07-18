<?php

declare(strict_types=1);

namespace App\Controller\Warehouse;

use App\Entity\DodaciList;
use App\Entity\DodaciListPage;
use App\Entity\User;
use App\Repository\DodaciListRepository;
use App\Security\WarehouseRole;
use App\Service\Warehouse\DodaciListStorage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/sklad/dodaci-list', name: 'warehouse_dodaci_list_')]
#[IsGranted(WarehouseRole::EDIT)]
final class DodaciListArchiveController extends AbstractController
{
    #[Route('', name: 'index', methods: ['GET'])]
    public function index(DodaciListRepository $repo): Response
    {
        return $this->render('warehouse/dodaci_list/index.html.twig', [
            'items' => $repo->findAllNewestFirst(),
        ]);
    }

    /** Multipart: pages[] v pořadí stránek → nový archiv. */
    #[Route('/upload', name: 'upload', methods: ['POST'])]
    public function upload(
        Request $request,
        EntityManagerInterface $em,
        DodaciListStorage $storage,
    ): JsonResponse {
        if (!$this->isCsrfTokenValid('dodaci_list_upload', (string) $request->request->get('_token'))) {
            return $this->json(['ok' => false, 'message' => 'Neplatný CSRF token.'], 403);
        }
        $bag = $request->files->get('pages');
        /** @var list<\Symfony\Component\HttpFoundation\File\UploadedFile> $files */
        $files = \is_array($bag) ? \array_values(\array_filter($bag)) : [];
        if ([] === $files) {
            return $this->json(['ok' => false, 'message' => 'Nahrajte alespoň jednu stránku (obrázek).'], 400);
        }
        if (\count($files) > 40) {
            return $this->json(['ok' => false, 'message' => 'Max. 40 stránek na jeden dodací list.'], 400);
        }

        $list = new DodaciList();
        $u = $this->getUser() instanceof User ? $this->getUser() : null;
        if (null !== $u) {
            $list->setCreatedBy($u);
        }
        $docNo = \trim((string) $request->request->get('documentNumber', ''));
        $docDateRaw = \trim((string) $request->request->get('documentDate', ''));
        if ('' !== $docNo) {
            $list->setDocumentNumber($docNo);
        }
        if ('' !== $docDateRaw) {
            try {
                $list->setDocumentDate(new \DateTimeImmutable($docDateRaw));
            } catch (\Exception) {
                // ignore bad date on upload
            }
        }

        $em->persist($list);
        $em->flush(); // potřebujeme id pro adresář

        try {
            $storage->storePages($list, $files);
            $em->flush();
        } catch (\Throwable $e) {
            $storage->deleteAllFiles($list);
            $em->remove($list);
            $em->flush();

            return $this->json(['ok' => false, 'message' => $e->getMessage()], 400);
        }

        return $this->json($this->serializeList($list, true));
    }

    #[Route('/{id}/meta', name: 'meta', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function updateMeta(Request $request, DodaciList $list, EntityManagerInterface $em): JsonResponse
    {
        $payload = \json_decode((string) $request->getContent(), true);
        if (!\is_array($payload)) {
            return $this->json(['ok' => false, 'message' => 'Neplatný JSON.'], 400);
        }
        if (!$this->isCsrfTokenValid('dodaci_list_meta', (string) ($payload['_token'] ?? ''))) {
            return $this->json(['ok' => false, 'message' => 'Neplatný CSRF token.'], 403);
        }
        if (\array_key_exists('documentNumber', $payload)) {
            $list->setDocumentNumber(null !== $payload['documentNumber'] ? (string) $payload['documentNumber'] : null);
        }
        if (\array_key_exists('documentDate', $payload)) {
            $raw = $payload['documentDate'];
            if (null === $raw || '' === $raw) {
                $list->setDocumentDate(null);
            } else {
                try {
                    $list->setDocumentDate(new \DateTimeImmutable((string) $raw));
                } catch (\Exception) {
                    return $this->json(['ok' => false, 'message' => 'Neplatné datum.'], 400);
                }
            }
        }
        $em->flush();

        return $this->json(['ok' => true, 'item' => $this->serializeList($list, false)]);
    }

    #[Route('/{id}/pages.json', name: 'pages_json', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function pagesJson(DodaciList $list): JsonResponse
    {
        return $this->json($this->serializeList($list, true));
    }

    #[Route('/{id}/page/{pageId}', name: 'page_file', methods: ['GET'], requirements: ['id' => '\d+', 'pageId' => '\d+'])]
    public function pageFile(DodaciList $list, int $pageId, DodaciListStorage $storage): Response
    {
        $page = null;
        foreach ($list->getPages() as $p) {
            if ($p->getId() === $pageId) {
                $page = $p;
                break;
            }
        }
        if (!$page instanceof DodaciListPage) {
            throw $this->createNotFoundException();
        }
        $path = $storage->absolutePath($page);
        if (!\is_file($path)) {
            throw $this->createNotFoundException('Soubor stránky chybí na disku.');
        }
        $response = new BinaryFileResponse($path);
        $response->headers->set('Content-Type', $page->getMimeType());
        $response->setContentDisposition(
            ResponseHeaderBag::DISPOSITION_INLINE,
            $page->getOriginalFilename()
        );

        return $response;
    }

    #[Route('/{id}/smazat', name: 'delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function delete(
        Request $request,
        DodaciList $list,
        EntityManagerInterface $em,
        DodaciListStorage $storage,
    ): Response {
        if (!$this->isCsrfTokenValid('dodaci_list_delete_'.$list->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Neplatný CSRF token.');

            return $this->redirectToRoute('warehouse_dodaci_list_index');
        }
        $label = $list->getLabel();
        $storage->deleteAllFiles($list);
        $em->remove($list);
        $em->flush();
        $this->addFlash('success', 'Dodací list «'.$label.'» byl smazán (soubory i záznam).');

        return $this->redirectToRoute('warehouse_dodaci_list_index');
    }

    /**
     * @return array{ok: bool, id: int, documentNumber: ?string, documentDate: ?string, pageCount: int, createdAt: string, prijemUrl: string, pages?: list<array{id: int, position: int, originalFilename: string, url: string}>}
     */
    private function serializeList(DodaciList $list, bool $withPages): array
    {
        $id = (int) $list->getId();
        $out = [
            'ok' => true,
            'id' => $id,
            'documentNumber' => $list->getDocumentNumber(),
            'documentDate' => $list->getDocumentDate()?->format('Y-m-d'),
            'pageCount' => $list->getPageCount(),
            'createdAt' => $list->getCreatedAt()->format('c'),
            'prijemUrl' => $this->generateUrl('warehouse_spool_receive_sealed_dodaci', ['archive' => $id]),
        ];
        if ($withPages) {
            $pages = [];
            foreach ($list->getPages() as $p) {
                $pages[] = [
                    'id' => (int) $p->getId(),
                    'position' => $p->getPosition(),
                    'originalFilename' => $p->getOriginalFilename(),
                    'url' => $this->generateUrl('warehouse_dodaci_list_page_file', [
                        'id' => $id,
                        'pageId' => (int) $p->getId(),
                    ]),
                ];
            }
            $out['pages'] = $pages;
        }

        return $out;
    }
}
