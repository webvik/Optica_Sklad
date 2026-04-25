<?php

namespace App\Controller\Warehouse;

use App\Entity\CableType;
use App\Entity\User;
use App\Form\CableTypeFormType;
use App\Repository\CableTypeRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/sklad/cable-type', name: 'warehouse_cable_type_')]
final class CableTypeController extends AbstractController
{
    #[Route('', name: 'index', methods: ['GET'])]
    public function index(CableTypeRepository $repo): Response
    {
        return $this->render('warehouse/cable_type/index.html.twig', [
            'items' => $repo->findBy([], ['name' => 'ASC']),
        ]);
    }

    #[Route('/new', name: 'new', methods: ['GET', 'POST'])]
    public function new(Request $request, EntityManagerInterface $em): Response
    {
        $c = new CableType();
        $form = $this->createForm(CableTypeFormType::class, $c);
        $form->handleRequest($request);
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
        ]);
    }

    #[Route('/{id}/edit', name: 'edit', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function edit(Request $request, CableType $cableType, EntityManagerInterface $em): Response
    {
        $form = $this->createForm(CableTypeFormType::class, $cableType);
        $form->handleRequest($request);
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
        ]);
    }
}
