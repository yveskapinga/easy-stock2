<?php
// src/Controller/PointOfSellController.php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;
use App\Service\CartService;

class PointOfSellController extends AbstractController
{
    private CartService $cartService;
    
    public function __construct(CartService $cartService)
    {
        $this->cartService = $cartService;
    }
    
    /**
     * Page principale du point de vente
     */
    #[Route('/point-de-vente', name: 'app_point_of_sell')]
    public function index(): Response
    {
        // Vérifier l'authentification
        // if (!$this->cartService->isAuthenticated()) {
        //     $this->addFlash('error', 'Veuillez vous connecter pour accéder au point de vente');
        //     return $this->redirectToRoute('app_login');
        // }
        
        // Récupérer les données formatées du panier
        $displayData = $this->cartService->getDisplayData();
        
        return $this->render('point_of_sell/index.html.twig', [
            'cart' => $displayData['cart'],
            'has_cart' => $displayData['has_cart'],
            'totals' => $displayData['totals'],
            'user' => $this->cartService->getCurrentUser(),
        ]);
    }
    
    /**
     * API: Scanner un article
     */
    #[Route('/api/pos/scan', name: 'api_pos_scan', methods: ['POST'])]
    public function scanItem(Request $request): JsonResponse
    {
        try {
            $data = json_decode($request->getContent(), true);
            
            if (empty($data['barcode'])) {
                return $this->jsonError('Code barre requis', 400);
            }
            
            $customerId = $data['customer_id'] ?? null;
            $quantity = $data['quantity'] ?? 1;
            
            $response = $this->cartService->scanItem($data['barcode'], $customerId, $quantity);
            
            // Formater la réponse pour l'affichage
            if (isset($response['items'])) {
                $formattedItems = [];
                foreach ($response['items'] as $item) {
                    $formattedItems[] = [
                        'id' => $item['item_id'] ?? null,
                        'product_id' => null, // L'API ne renvoie pas product_id dans le scan
                        'product_name' => $item['product_name'] ?? 'Produit inconnu',
                        'unit_price' => $item['unit_price'] ?? 0,
                        'quantity' => $item['quantity'] ?? 0,
                        'item_total' => ($item['unit_price'] ?? 0) * ($item['quantity'] ?? 0)
                    ];
                }
                $response['formatted_items'] = $formattedItems;
            }
            
            return $this->json($response);
            
        } catch (\Exception $e) {
            return $this->jsonError($e->getMessage(), 500);
        }
    }
    
    /**
     * API: Récupérer le panier actuel
     */
    #[Route('/api/pos/cart', name: 'api_pos_cart', methods: ['GET'])]
    public function getCart(): JsonResponse
    {
        try {
            $displayData = $this->cartService->getDisplayData();
            
            return $this->json([
                'success' => true,
                'has_cart' => $displayData['has_cart'],
                'cart' => $displayData['cart'],
                'totals' => $displayData['totals']
            ]);
            
        } catch (\Exception $e) {
            return $this->jsonError($e->getMessage(), 500);
        }
    }
    
    /**
     * API: Augmenter la quantité d'un article (+)
     */
    #[Route('/api/pos/cart/item/increase', name: 'api_pos_cart_item_increase', methods: ['PATCH'])]
    public function increaseItemQuantity(Request $request): JsonResponse
    {
        try {
            $data = json_decode($request->getContent(), true);
            
            if (empty($data['item_id'])) {
                return $this->jsonError('ID de l\'article requis', 400);
            }
            
            $response = $this->cartService->increaseItemQuantity($data['item_id']);
            
            return $this->json($response);
            
        } catch (\Exception $e) {
            return $this->jsonError($e->getMessage(), 500);
        }
    }
    
    /**
     * API: Diminuer la quantité d'un article (-)
     */
    #[Route('/api/pos/cart/item/decrease', name: 'api_pos_cart_item_decrease', methods: ['PATCH'])]
    public function decreaseItemQuantity(Request $request): JsonResponse
    {
        try {
            $data = json_decode($request->getContent(), true);
            
            if (empty($data['item_id'])) {
                return $this->jsonError('ID de l\'article requis', 400);
            }
            
            $response = $this->cartService->decreaseItemQuantity($data['item_id']);
            
            return $this->json($response);
            
        } catch (\Exception $e) {
            return $this->jsonError($e->getMessage(), 500);
        }
    }
    
    /**
     * API: Supprimer un article du panier (poubelle)
     */
    #[Route('/api/pos/cart/item/remove', name: 'api_pos_cart_item_remove', methods: ['DELETE'])]
    public function removeItem(Request $request): JsonResponse
    {
        try {
            $data = json_decode($request->getContent(), true);
            
            if (empty($data['item_id'])) {
                return $this->jsonError('ID de l\'article requis', 400);
            }
            
            $response = $this->cartService->removeItem($data['item_id']);
            
            return $this->json($response);
            
        } catch (\Exception $e) {
            return $this->jsonError($e->getMessage(), 500);
        }
    }
    
    /**
     * API: Suspendre le panier
     */
    #[Route('/api/pos/cart/suspend', name: 'api_pos_cart_suspend', methods: ['POST'])]
    public function suspendCart(): JsonResponse
    {
        try {
            if (!$this->cartService->hasActiveCart()) {
                return $this->jsonError('Aucun panier actif à suspendre', 400);
            }
            
            $response = $this->cartService->suspendCart();
            
            return $this->json($response);
            
        } catch (\Exception $e) {
            return $this->jsonError($e->getMessage(), 500);
        }
    }
    
    /**
     * API: Annuler le panier
     */
    #[Route('/api/pos/cart/cancel', name: 'api_pos_cart_cancel', methods: ['POST'])]
    public function cancelCart(): JsonResponse
    {
        try {
            if (!$this->cartService->hasActiveCart()) {
                return $this->jsonError('Aucun panier actif à annuler', 400);
            }
            
            $response = $this->cartService->cancelCart();
            
            return $this->json($response);
            
        } catch (\Exception $e) {
            return $this->jsonError($e->getMessage(), 500);
        }
    }
    
    /**
     * API: Activer un panier suspendu
     */
    #[Route('/api/pos/cart/activate', name: 'api_pos_cart_activate', methods: ['POST'])]
    public function activateCart(Request $request): JsonResponse
    {
        try {
            $data = json_decode($request->getContent(), true);
            
            if (empty($data['cart_id'])) {
                return $this->jsonError('ID du panier requis', 400);
            }
            
            $response = $this->cartService->activateCart($data['cart_id']);
            
            return $this->json($response);
            
        } catch (\Exception $e) {
            return $this->jsonError($e->getMessage(), 500);
        }
    }
    
    /**
     * API: Finaliser la vente
     */
    #[Route('/api/pos/cart/finalize', name: 'api_pos_cart_finalize', methods: ['POST'])]
    public function finalizeCart(Request $request): JsonResponse
    {
        try {
            if (!$this->cartService->hasActiveCart()) {
                return $this->jsonError('Aucun panier actif à finaliser', 400);
            }
            
            $data = json_decode($request->getContent(), true);
            
            // Validation basique
            if (empty($data['payments']) || !is_array($data['payments'])) {
                return $this->jsonError('Informations de paiement requises', 400);
            }
            
            $response = $this->cartService->finalizeCart($data);
            
            return $this->json($response);
            
        } catch (\Exception $e) {
            return $this->jsonError($e->getMessage(), 500);
        }
    }
    
    /**
     * API: Rechercher un client
     */
    #[Route('/api/pos/customer/find', name: 'api_pos_customer_find', methods: ['POST'])]
    public function findCustomer(Request $request): JsonResponse
    {
        try {
            $data = json_decode($request->getContent(), true);
            
            if (empty($data['identifierValue'])) {
                return $this->jsonError('Identifiant client requis', 400);
            }
            
            $response = $this->cartService->findCustomer($data['identifierValue']);
            
            return $this->json($response);
            
        } catch (\Exception $e) {
            return $this->jsonError($e->getMessage(), 500);
        }
    }
    
    /**
     * Helper pour les réponses JSON d'erreur
     */
    private function jsonError(string $message, int $status = 400): JsonResponse
    {
        return $this->json([
            'success' => false,
            'message' => $message,
            'error' => true
        ], $status);
    }
}