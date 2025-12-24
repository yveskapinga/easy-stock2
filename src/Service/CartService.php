<?php
// src/Service/CartService.php

namespace App\Service;

use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class CartService extends ApiService
{
    private ?int $currentCartId = null;
    protected string $apiBaseUrl;
    
    public function __construct(
        HttpClientInterface $httpClient,
        RequestStack $requestStack,
    ) {
        $this->apiBaseUrl = $_ENV['API_BASE_URL'] ?? 'http://localhost:3000';
        parent::__construct($httpClient, $requestStack, $this->apiBaseUrl);
        $this->currentCartId = $requestStack->getSession()->get('current_cart_id');
    }
    
    /******************************************************************
     * MÉTHODES SPÉCIFIQUES AU PANIER
     ******************************************************************/
    
    /**
     * Scanner un article (création ou ajout au panier)
     * Endpoint: POST /api/cart/scan/
     */
    public function scanItem(string $barcode, ?int $customerId = null, int $quantity = 1): array
    {
        $data = [
            'station_id' => $this->getCurrentShopId() ?? 1,
            'shop_id' => $this->getCurrentShopId(),
            'user_id' => $this->getCurrentUserId(),
            'barcode' => $barcode,
            'customer_id' => $customerId,
            'quantity' => $quantity
        ];
        
        $response = $this->postRequest('api/cart/scan/', $data);
        
        // Stocker l'ID du panier si retourné
        if (isset($response['cart_id'])) {
            $this->setCurrentCartId($response['cart_id']);
        }
        
        // Stocker les noms de produits dans la session pour référence future
        if (isset($response['items']) && is_array($response['items'])) {
            $this->storeProductNames($response['items']);
        }
        
        return $response;
    }
    
    /**
     * Récupérer les articles d'un panier actif
     * Endpoint: GET /api/cart/{cart_id}/active-items
     */
    public function getActiveCartItems(?int $cartId = null): array
    {
        $cartId = $cartId ?? $this->currentCartId;
        
        if (!$cartId) {
            return ['success' => false, 'message' => 'Aucun panier actif'];
        }
        
        $response = $this->getRequest("api/cart/{$cartId}/active-items");
        
        // Si on a des items dans la réponse API, on les formate pour l'affichage
        if (isset($response['data']['items']) && is_array($response['data']['items'])) {
            $items = $response['data']['items'];
            $formattedItems = [];
            
            foreach ($items as $item) {
                // Récupérer le nom du produit depuis la session si disponible
                $productName = $this->getProductName($item['product_id'] ?? null);
                
                $formattedItems[] = [
                    'id' => $item['id'],
                    'product_id' => $item['product_id'],
                    'product_name' => $productName ?? ('Produit #' . ($item['product_id'] ?? 'N/A')),
                    'unit_price' => $item['unit_price'] ?? 0,
                    'quantity' => $item['quantity'] ?? 0,
                    'shop_id' => $item['shop_id'] ?? null,
                    'discount' => $item['discount'] ?? 0,
                    'added_at' => $item['added_at'] ?? null,
                    'item_total' => ($item['unit_price'] ?? 0) * ($item['quantity'] ?? 0)
                ];
            }
            
            $response['data']['items'] = $formattedItems;
        }
        
        return $response;
    }
    
    /**
     * Formater les données du panier pour l'affichage
     */
    public function getFormattedCartData(?int $cartId = null): array
    {
        $response = $this->getActiveCartItems($cartId);
        
        if (!isset($response['data'])) {
            return [
                'id' => null,
                'status' => 'inactive',
                'items' => [],
                'totals' => $this->calculateCartTotal([])
            ];
        }
        
        $cartData = $response['data'];
        $totals = $this->calculateCartTotal($cartData);
        
        return [
            'id' => $cartData['id'] ?? null,
            'status' => $cartData['status'] ?? 'unknown',
            'user_id' => $cartData['user_id'] ?? null,
            'created_at' => $cartData['created_at'] ?? null,
            'items' => $cartData['items'] ?? [],
            'totals' => $totals
        ];
    }
    
    /**
     * Stocker les noms des produits dans la session
     */
    private function storeProductNames(array $items): void
    {
        $productNames = $this->session->get('product_names', []);
        
        foreach ($items as $item) {
            if (isset($item['product_name']) && isset($item['item_id'])) {
                $productNames[$item['item_id']] = [
                    'name' => $item['product_name'],
                    'product_id' => $this->extractProductIdFromItem($item)
                ];
            }
        }
        
        $this->session->set('product_names', $productNames);
    }
    
    /**
     * Récupérer le nom d'un produit depuis la session
     */
    private function getProductName(?int $productId): ?string
    {
        $productNames = $this->session->get('product_names', []);
        
        // Chercher par product_id dans les données stockées
        foreach ($productNames as $data) {
            if (isset($data['product_id']) && $data['product_id'] == $productId) {
                return $data['name'] ?? null;
            }
        }
        
        return null;
    }
    
    /**
     * Extraire l'ID du produit depuis un item (pour compatibilité)
     */
    private function extractProductIdFromItem(array $item): ?int
    {
        // Différentes façons dont l'API pourrait fournir l'ID du produit
        if (isset($item['product_id'])) {
            return $item['product_id'];
        }
        
        // Si l'API ne fournit pas product_id, on pourrait le déduire d'autres champs
        // Pour l'instant, retourner null si non trouvé
        return null;
    }
    
    /**
     * Modifier la quantité d'un article dans le panier
     * Endpoint: PATCH /api/cart/cart-items/{itemId}
     */
    public function updateItemQuantity(int $itemId, int $delta, ?int $cartId = null): array
    {
        $cartId = $cartId ?? $this->currentCartId;
        
        if (!$cartId) {
            return ['success' => false, 'message' => 'Aucun panier actif'];
        }
        
        // Le body attendu par Express
        $data = [
            'cart_id' => $cartId,
            'delta'   => $delta
        ];
        
        // URL avec l'itemId
        $endpoint = sprintf('api/cart/items/%d', $itemId);
        
        return $this->patchRequest($endpoint, $data);
    }

    
    /**
     * Augmenter la quantité d'un article (bouton +)
     */
    public function increaseItemQuantity(int $itemId, ?int $cartId = null): array
    {
        return $this->updateItemQuantity($itemId, 1, $cartId);
    }
    
    /**
     * Diminuer la quantité d'un article (bouton -)
     */
    public function decreaseItemQuantity(int $itemId, ?int $cartId = null): array
    {
        return $this->updateItemQuantity($itemId, -1, $cartId);
    }
    
    /**
     * Supprimer complètement un article du panier (bouton poubelle)
     */
    public function removeItem(int $itemId, ?int $cartId = null): array
    {
        return $this->updateItemQuantity($itemId, -999, $cartId);
    }
    
    /**
     * Suspendre un panier
     * Endpoint: PATCH /api/cart/suspend
     */
    public function suspendCart(?int $cartId = null): array
    {
        $cartId = $cartId ?? $this->currentCartId;
        
        if (!$cartId) {
            return ['success' => false, 'message' => 'Aucun panier actif'];
        }
        
        $data = [
            'cart_id' => $cartId,
            'user_id' => $this->getCurrentUserId()
        ];
        
        $response = $this->patchRequest('api/cart/suspend', $data);
        
        if ($response['success'] ?? false) {
            $this->clearCurrentCartId();
        }
        
        return $response;
    }
    
    /**
     * Annuler un panier
     * Endpoint: PATCH /api/cart/cancel/{cart_id}
     */
    public function cancelCart(?int $cartId = null): array
    {
        $cartId = $cartId ?? $this->currentCartId;
        
        if (!$cartId) {
            return ['success' => false, 'message' => 'Aucun panier actif'];
        }
        
        $data = [
            'user_id' => $this->getCurrentUserId()
        ];
        
        $response = $this->patchRequest("api/cart/cancel/{$cartId}", $data);
        
        if ($response['success'] ?? false) {
            $this->clearCurrentCartId();
            // Nettoyer aussi les noms de produits pour ce panier
            $this->session->remove('product_names');
        }
        
        return $response;
    }
    
    /**
     * Activer un panier suspendu
     * Endpoint: PATCH /api/cart/activate
     */
    public function activateCart(int $cartId): array
    {
        $data = [
            'cart_id' => $cartId,
            'user_id' => $this->getCurrentUserId()
        ];
        
        $response = $this->patchRequest('api/cart/activate', $data);
        
        if ($response['success'] ?? false) {
            $this->setCurrentCartId($cartId);
        }
        
        return $response;
    }
    
    /**
     * Finaliser une vente (payer le panier)
     * Endpoint: POST /api/cart/finalize
     */
    public function finalizeCart(array $paymentData, ?int $cartId = null): array
    {
        $cartId = $cartId ?? $this->currentCartId;
        
        if (!$cartId) {
            return ['success' => false, 'message' => 'Aucun panier actif'];
        }
        
        // Structure par défaut des données de paiement
        $defaultData = [
            'cart_id' => $cartId,
            'discount' => 0,
            'loyalty_points_used' => 0,
            'payments' => []
        ];
        
        $data = array_merge($defaultData, $paymentData);
        
        $response = $this->postRequest('api/cart/finalize', $data);
        
        if ($response['success'] ?? false) {
            $this->clearCurrentCartId();
            // Nettoyer les noms de produits après finalisation
            $this->session->remove('product_names');
        }
        
        return $response;
    }
    
    /**
     * Rechercher un client par identifiant
     * Endpoint: POST /api/customer/find
     */
    public function findCustomer(string $identifierValue): array
    {
        $data = ['identifierValue' => $identifierValue];
        return $this->postRequest('api/customer/find', $data);
    }
    
    /******************************************************************
     * GESTION DE L'ÉTAT DU PANIER COURANT
     ******************************************************************/
    
    /**
     * Définir le panier courant
     */
    public function setCurrentCartId(int $cartId): void
    {
        $this->currentCartId = $cartId;
        $this->session->set('current_cart_id', $cartId);
    }
    
    /**
     * Récupérer l'ID du panier courant
     */
    public function getCurrentCartId(): ?int
    {
        return $this->currentCartId;
    }
    
    /**
     * Effacer le panier courant
     */
    public function clearCurrentCartId(): void
    {
        $this->currentCartId = null;
        $this->session->remove('current_cart_id');
    }
    
    /**
     * Vérifier si un panier est actif
     */
    public function hasActiveCart(): bool
    {
        return $this->currentCartId !== null;
    }
    
    /**
     * Récupérer le statut du panier
     */
    public function getCartStatus(?int $cartId = null): string
    {
        $cartId = $cartId ?? $this->currentCartId;
        
        if (!$cartId) {
            return 'inactive';
        }
        
        try {
            $response = $this->getActiveCartItems($cartId);
            if (isset($response['data']['status'])) {
                return $response['data']['status'];
            }
            return 'unknown';
        } catch (\Exception $e) {
            return 'error';
        }
    }
    
    /**
     * Calculer le total du panier
     */
    public function calculateCartTotal(array $cartData): array
    {
        $subtotal = 0;
        $discount = 0;
        
        $items = $cartData['items'] ?? [];
        
        if (empty($items)) {
            return [
                'subtotal' => 0,
                'discount' => 0,
                'tax' => 0,
                'total' => 0,
                'item_count' => 0
            ];
        }
        
        $itemCount = 0;
        
        foreach ($items as $item) {
            $itemTotal = ($item['unit_price'] ?? 0) * ($item['quantity'] ?? 0);
            $subtotal += $itemTotal;
            $itemCount += ($item['quantity'] ?? 0);
            
            // Ajouter les réductions si présentes
            if (isset($item['discount']) && $item['discount'] > 0) {
                $discount += $item['discount'];
            }
        }
        
        $tax = $subtotal * 0.2; // TVA 20%
        $total = $subtotal + $tax - $discount;
        
        return [
            'subtotal' => round($subtotal, 2),
            'discount' => round($discount, 2),
            'tax' => round($tax, 2),
            'total' => round($total, 2),
            'item_count' => $itemCount
        ];
    }
    
    /**
     * Récupérer les données formatées pour l'affichage
     */
    public function getDisplayData(): array
    {
        $cartId = $this->getCurrentCartId();
        
        if (!$cartId) {
            return [
                'has_cart' => false,
                'cart' => null,
                'totals' => $this->calculateCartTotal([])
            ];
        }
        
        $cartData = $this->getFormattedCartData($cartId);
        
        return [
            'has_cart' => true,
            'cart' => $cartData,
            'totals' => $cartData['totals'] ?? $this->calculateCartTotal([])
        ];
    }
}