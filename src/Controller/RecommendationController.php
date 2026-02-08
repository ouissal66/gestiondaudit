<?php

namespace App\Controller;

use App\Entity\Recommendation;
use App\Form\RecommendationType;
use App\Repository\RecommendationRepository;
use App\Repository\ReportRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class RecommendationController extends AbstractController
{
    private $entityManager;

    public function __construct(EntityManagerInterface $entityManager)
    {
        $this->entityManager = $entityManager;
    }

    #[Route('/recommendation', name: 'app_recommendation')]
    public function index(Request $request, RecommendationRepository $recommendationRepository): Response
    {
        $query = $request->query->get('q');
        
        if ($query) {
            $recommendations = $recommendationRepository->findBySearch($query);
        } else {
            $recommendations = $recommendationRepository->findBy([], ['createdAt' => 'DESC']);
        }

        return $this->render('recommendation/index.html.twig', [
            'recommendations' => $recommendations,
            'reports' => $recommendationRepository->getEntityManager()->getRepository(\App\Entity\Report::class)->findBy([], ['title' => 'ASC']),
        ]);
    }

    #[Route('/recommendation/{id}/edit', name: 'app_recommendation_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, Recommendation $recommendation, EntityManagerInterface $entityManager): Response
    {
        $form = $this->createForm(RecommendationType::class, $recommendation);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->flush();

            $this->addFlash('success', 'La recommandation a été mise à jour.');

            return $this->redirectToRoute('app_recommendation', [], Response::HTTP_SEE_OTHER);
        }

        return $this->render('recommendation/edit.html.twig', [
            'recommendation' => $recommendation,
            'form' => $form,
        ]);
    }

    #[Route('/recommendation/{id}', name: 'app_recommendation_show', methods: ['GET'])]
    public function show(Recommendation $recommendation): Response
    {
        return $this->render('recommendation/show.html.twig', [
            'recommendation' => $recommendation,
        ]);
    }

    #[Route('/recommendation/ask', name: 'app_recommendation_ask', methods: ['POST'])]
    public function ask(Request $request, ReportRepository $reportRepository): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $message = strtolower($data['message'] ?? '');

        if (empty($message)) {
            return $this->json(['response' => 'Je ne comprends pas votre demande.'], 400);
        }

        $response = "";
        
        // Intent detection
        if (str_contains($message, 'aide') || str_contains($message, 'quoi faire') || $message === 'help') {
            $response = "Je suis là pour vous aider avec vos audits ! Voici ce que je peux faire :\n\n";
            $response .= "🔍 **Recherche** : \"Montre moi les rapports de sécurité\"\n";
            $response .= "⚠️ **Urgence** : \"Quels sont les rapports critiques ?\"\n";
            $response .= "📈 **Performance** : \"Trouve les rapports avec un score faible\"\n";
            $response .= "💡 **Conseils** : \"Comment améliorer mon score d'audit ?\"";
            return $this->json(['response' => $response]);
        }

        if (str_contains($message, 'score') && (str_contains($message, 'bas') || str_contains($message, 'faible') || str_contains($message, 'mauvais'))) {
             $reports = $reportRepository->createQueryBuilder('r')
                ->andWhere('r.score < 50')
                ->orderBy('r.score', 'ASC')
                ->getQuery()
                ->getResult();
             
             if (empty($reports)) {
                 $response = "Félicitations ! Aucun rapport n'a un score inférieur à 50/100.";
             } else {
                 $response = "Voici les rapports nécessitant une attention immédiate (score < 50) :\n\n";
                 foreach ($reports as $report) {
                     $response .= "❌ **" . $report->getTitle() . "** (Score: " . $report->getScore() . "/100)\n";
                 }
             }
             return $this->json(['response' => $response]);
        }

        if (str_contains($message, 'critique') || str_contains($message, 'urgent') || str_contains($message, 'danger')) {
            $reports = $reportRepository->findBy(['status' => 'Critique']);
            if (empty($reports)) {
                $response = "Bonne nouvelle, aucun rapport n'est marqué comme 'Critique' actuellement.";
            } else {
                $response = "Attention ! J'ai trouvé **" . count($reports) . "** rapport(s) critique(s) :\n\n";
                foreach ($reports as $report) {
                    $response .= "🔴 **" . $report->getTitle() . "** - " . substr($report->getDescription(), 0, 100) . "...\n\n";
                }
            }
            return $this->json(['response' => $response]);
        }

        if (str_contains($message, 'améliorer') || str_contains($message, 'conseil') || str_contains($message, 'recommandation')) {
            $response = "Pour améliorer vos scores d'audit, voici quelques conseils généraux :\n\n";
            $response .= "1. 📝 **Documentation** : Assurez-vous que chaque point de contrôle est documenté avec des preuves.\n";
            $response .= "2. ⏱️ **Réactivité** : Traitez les points 'Critiques' sous 24h.\n";
            $response .= "3. 🔍 **Précision** : Utilisez des termes techniques précis dans vos descriptions.\n\n";
            $response .= "Voulez-vous que j'analyse un rapport spécifique ? (Taper le nom du rapport)";
            return $this->json(['response' => $response]);
        }

        // Default search logic
        $reports = $reportRepository->findBySearch($message);

        if (empty($reports)) {
             $response = "Je n'ai trouvé aucun rapport correspondant à '" . $message . "'. \n\nTapez '**aide**' pour voir comment je peux vous assister !";
        } else {
            $count = count($reports);
            $response = "J'ai trouvé **$count** résultat(s) pour votre recherche :\n\n";
            
            foreach (array_slice($reports, 0, 3) as $report) {
                $statusColor = match($report->getStatus()) {
                    'Validé' => '🟢',
                    'Critique' => '🔴',
                    'En cours' => '🔵',
                    default => '⚪'
                };
                
                $response .= "$statusColor **" . $report->getTitle() . "** (Score: " . ($report->getScore() ?? 'N/A') . ")\n";
                $response .= "> " . substr($report->getDescription(), 0, 120) . "...\n\n";
            }
            
            if ($count > 3) {
                $response .= "_...et " . ($count - 3) . " autres résultats._";
            }
        }

        return $this->json(['response' => $response]);
    }

    #[Route('/recommendation/{id}/delete', name: 'app_recommendation_delete', methods: ['POST'])]
    public function delete(Request $request, Recommendation $recommendation): Response
    {
        if ($this->isCsrfTokenValid('delete' . $recommendation->getId(), $request->request->get('_token'))) {
            $this->entityManager->remove($recommendation);
            $this->entityManager->flush();
            
            if ($request->isXmlHttpRequest()) {
                $this->addFlash('success', 'La recommandation a été supprimée.');
                return $this->json(['success' => true, 'message' => 'La recommandation a été supprimée.']);
            }
            
            $this->addFlash('success', 'La recommandation a été supprimée.');
        }

        if ($request->isXmlHttpRequest()) {
            return $this->json(['success' => false, 'message' => 'Token CSRF invalide.'], 400);
        }

        $referer = $request->headers->get('referer');
        if ($referer && str_contains($referer, '/recommendation/' . $recommendation->getId())) {
            return $this->redirectToRoute('app_recommendation');
        }

        return $this->redirect($referer ?: $this->generateUrl('app_recommendation'));
    }
}
