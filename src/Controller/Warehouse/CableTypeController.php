<?php

namespace App\Controller\Warehouse;

use App\Entity\CableType;
use App\Entity\User;
use App\Form\CableTypeFormType;
use App\Repository\CableFamilyRepository;
use App\Repository\CableTypeRepository;
use App\Service\Warehouse\CableTypeOcrMatcher;
use App\Service\Warehouse\InventuraBriefGroupLabel;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/sklad/cable-type', name: 'warehouse_cable_type_')]
final class CableTypeController extends AbstractController
{
    private const SESSION_OCR_PREFILL = 'warehouse_ct_ocr_prefill_v1';

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(CableTypeRepository $repo): Response
    {
        return $this->render('warehouse/cable_type/index.html.twig', [
            'items' => $repo->findBy([], ['name' => 'ASC']),
        ]);
    }

    #[Route('/new', name: 'new', methods: ['GET', 'POST'])]
    public function new(
        Request $request,
        EntityManagerInterface $em,
        CableTypeOcrMatcher $matcher,
        CableFamilyRepository $cableFamilyRepository,
        CableTypeRepository $cableTypeRepository,
    ): Response {
        $c = new CableType();
        $this->consumeOcrPrefillFromSessionIfAny($request, $matcher, $c, $cableFamilyRepository);

        $form = $this->createForm(CableTypeFormType::class, $c);
        $form->handleRequest($request);
        if ($form->isSubmitted()) {
            $this->synchronizeCableTypeListName($c);
        }
        if ($form->isSubmitted() && $form->isValid()) {
            $u = $this->getUser();
            if ($u instanceof User) {
                $c->setCreatedBy($u);
                $c->setUpdatedBy($u);
            }
            $em->persist($c);
            $em->flush();
            $this->addFlash('success', 'Typ kabelu byl uložen.');

            return $this->redirectToRoute('warehouse_cable_type_index');
        }

        return $this->render('warehouse/cable_type/form.html.twig', [
            'form' => $form,
            'title' => 'Nový typ kabelu',
            'cableListLabelPreview' => InventuraBriefGroupLabel::forCableType($c),
            'codeFieldPlaceholder' => $cableTypeRepository->findExampleStockCode(),
        ]);
    }

    /**
     * Text štítku (z klienta) → jen heuristické pole pro formulář; bez párování s katalogem.
     */
    #[Route('/label-ocr-suggest', name: 'label_ocr_suggest', methods: ['POST'])]
    public function labelOcrSuggest(
        Request $request,
        CableTypeOcrMatcher $matcher,
        CableFamilyRepository $cableFamilyRepository,
    ): JsonResponse {
        $payload = json_decode((string) $request->getContent(), true);
        if (!\is_array($payload)) {
            return new JsonResponse(['ok' => false], 400);
        }
        $csrf = (string) ($payload['csrf'] ?? '');
        if (!$this->isCsrfTokenValid('cable_type_label_ocr_suggest', $csrf)) {
            return new JsonResponse(['ok' => false, 'error' => 'csrf'], 403);
        }

        $text = \trim((string) ($payload['text'] ?? ''));
        if ('' === $text || \mb_strlen($text) > 12000 || \strlen($text) > 65535) {
            return new JsonResponse(['ok' => false, 'error' => 'text'], 400);
        }

        $h = $matcher->extractSuggestedFields($text);
        /** @var array<string, scalar> $suggested */
        $suggested = [];

        $full = \trim((string) ($h['fullText'] ?? ''));
        if ('' !== $full) {
            $suggested['fullDescription'] = $full;
        }

        if (isset($h['fiberCount'])) {
            $n = (int) $h['fiberCount'];
            if ($n > 0 && $n < 2000) {
                $suggested['fiberCount'] = $n;
            }
        }

        $diamSrc = \trim(\str_replace(' ', '', (string) ($h['diameterMm'] ?? '')));
        $diamSrc = \str_replace(',', '.', $diamSrc);
        if ('' !== $diamSrc && \is_numeric($diamSrc)) {
            /** Pro HTML NumberType s českým formátem */
            $suggested['diameterMm'] = \str_replace('.', ',', (string) $diamSrc);
        }

        $cc = \trim((string) ($h['constructionCode'] ?? ''));
        if ('' !== $cc) {
            $suggested['constructionCode'] = \mb_substr($cc, 0, 31);
        }

        $fam = \trim((string) ($h['familyCode'] ?? ''));
        if ('' !== $fam && null !== $cableFamilyRepository->findOneBy(['code' => $fam, 'isActive' => true])) {
            $suggested['familyCode'] = $fam;
        }

        return new JsonResponse([
            'ok' => true,
            'suggested' => $suggested,
        ]);
    }

    /** Dočasný štítek z OCR před„Novým typem“ (GET URL by byl příliš dlouhý). */
    #[Route('/ocr-prefill-stash', name: 'ocr_prefill_stash', methods: ['POST'])]
    public function stashOcrPrefill(Request $request): JsonResponse
    {
        $payload = json_decode((string) $request->getContent(), true);
        if (!is_array($payload)) {
            return new JsonResponse(['ok' => false], 400);
        }
        $csrf = (string) ($payload['csrf'] ?? '');
        if (!$this->isCsrfTokenValid('cable_type_ocr_stash', $csrf)) {
            return new JsonResponse(['ok' => false, 'error' => 'csrf'], 403);
        }
        $text = trim((string) ($payload['text'] ?? ''));
        if ('' === $text || mb_strlen($text) > 12000) {
            return new JsonResponse(['ok' => false, 'error' => 'text'], 400);
        }

        $request->getSession()->set(self::SESSION_OCR_PREFILL, [
            'raw' => $text,
            'at' => time(),
        ]);

        return new JsonResponse(['ok' => true]);
    }

    #[Route('/{id}/edit', name: 'edit', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function edit(Request $request, CableType $cableType, EntityManagerInterface $em, CableTypeRepository $cableTypeRepository): Response
    {
        $form = $this->createForm(CableTypeFormType::class, $cableType);
        $form->handleRequest($request);
        if ($form->isSubmitted()) {
            $this->synchronizeCableTypeListName($cableType);
        }
        if ($form->isSubmitted() && $form->isValid()) {
            $u = $this->getUser();
            if ($u instanceof User) {
                $cableType->setUpdatedBy($u);
            }
            $em->flush();
            $this->addFlash('success', 'Změny byly uloženy.');

            return $this->redirectToRoute('warehouse_cable_type_index');
        }

        return $this->render('warehouse/cable_type/form.html.twig', [
            'form' => $form,
            'title' => 'Upravit: '.$cableType->getCode(),
            'cableListLabelPreview' => InventuraBriefGroupLabel::forCableType($cableType),
            'codeFieldPlaceholder' => $cableTypeRepository->findExampleStockCode($cableType->getCode()),
        ]);
    }

    private function consumeOcrPrefillFromSessionIfAny(
        Request $request,
        CableTypeOcrMatcher $matcher,
        CableType $cable,
        CableFamilyRepository $cableFamilyRepository,
    ): void {
        $sess = $request->getSession();
        $bag = $sess->get(self::SESSION_OCR_PREFILL);
        if (!\is_array($bag) || !isset($bag['raw']) || !\is_string($bag['raw'])) {
            return;
        }
        if ((\time() - (int) ($bag['at'] ?? 0)) > 900) {
            $sess->remove(self::SESSION_OCR_PREFILL);

            return;
        }

        $hints = $matcher->extractSuggestedFields($bag['raw']);
        if ('' !== ($hints['fullText'] ?? '')) {
            $cable->setFullDescription($hints['fullText']);
        }
        if (isset($hints['fiberCount'])) {
            $n = (int) $hints['fiberCount'];
            if ($n > 0 && $n < 2000) {
                $cable->setFiberCount($n);
            }
        }
        if ('' !== (string) ($hints['diameterMm'] ?? '')) {
            $cable->setDiameterMm((string) $hints['diameterMm']);
        }
        $cc = \trim((string) ($hints['constructionCode'] ?? ''));
        if ('' !== $cc) {
            $cable->setConstructionCode($cc);
        }
        $fam = \trim((string) ($hints['familyCode'] ?? ''));
        if ('' !== $fam && null !== $cableFamilyRepository->findOneBy(['code' => $fam, 'isActive' => true])) {
            $cable->setFamily($fam);
        }

        $sess->remove(self::SESSION_OCR_PREFILL);
        $this->addFlash(
            'info',
            'Předběžné údaje ze štítku (OCR): plný popis a strukturální pole — krátký název v seznamech se dopo uložení dopočítá z vláken · řady · Ø jako ve sloupci Skupina v inventuře. Doplňte kód zásoby a zkontrolujte údaje.'
        );
    }

    /** Stejný text jako sloupec „Skupina“ v krátké inventuře (název pro seznamy a cívky). */
    private function synchronizeCableTypeListName(CableType $cableType): void
    {
        $label = InventuraBriefGroupLabel::forCableType($cableType);
        $cableType->setName(\mb_substr($label, 0, 255));
    }
}
