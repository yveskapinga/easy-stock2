<?php

// src/Service/ApiService.php

namespace App\Service;

use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class ApiService
{
    protected HttpClientInterface $httpClient;
    protected SessionInterface $session;
    protected string $apiBaseUrl;

    public function __construct(
        HttpClientInterface $httpClient,
        RequestStack $requestStack,
        string $apiBaseUrl
    ) {
        $this->httpClient = $httpClient;
        $this->session = $requestStack->getSession();
        $this->apiBaseUrl = rtrim($apiBaseUrl, '/');
    }

    /**
     * Méthode générique pour les requêtes HTTP.
     */
    protected function makeRequest(
        string $method,
        string $endpoint,
        array $data = [],
        array $headers = []
    ): array {
        $url = $this->apiBaseUrl.'/'.ltrim($endpoint, '/');

        // Récupérer le token d'authentification
        $token = $this->getAuthToken();
        if ($token) {
            $headers['Authorization'] = 'BEARER '.$token;
        }

        // Headers par défaut
        $defaultHeaders = [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ];

        $headers = array_merge($defaultHeaders, $headers);

        $options = [
            'headers' => $headers,
            'verify_peer' => false,
            'verify_host' => false,
            'timeout' => 30,
        ];

        // Ajouter le corps pour POST, PUT, PATCH
        if (in_array($method, ['POST', 'PUT', 'PATCH']) && !empty($data)) {
            $options['json'] = $data;
        }

        try {
            $response = $this->httpClient->request($method, $url, $options);
            $statusCode = $response->getStatusCode();
            $content = $response->getContent(false);

            $result = json_decode($content, true) ?? ['message' => $content];

            if ($statusCode >= 400) {
                throw new HttpException($statusCode, $result['message'] ?? 'Erreur API', null, [], $statusCode);
            }

            return $result;
        } catch (\Exception $e) {
            throw new HttpException(500, 'Erreur de connexion API: '.$e->getMessage());
        }
    }

    /**
     * GET request.
     */
    protected function getRequest(string $endpoint, array $query = []): array
    {
        if (!empty($query)) {
            $endpoint .= '?'.http_build_query($query);
        }

        return $this->makeRequest('GET', $endpoint);
    }

    /**
     * POST request.
     */
    protected function postRequest(string $endpoint, array $data = []): array
    {
        return $this->makeRequest('POST', $endpoint, $data);
    }

    /**
     * PATCH request.
     */
    protected function patchRequest(string $endpoint, array $data = []): array
    {
        return $this->makeRequest('PATCH', $endpoint, $data);
    }

    /**
     * PUT request.
     */
    protected function putRequest(string $endpoint, array $data = []): array
    {
        return $this->makeRequest('PUT', $endpoint, $data);
    }

    /**
     * DELETE request.
     */
    protected function deleteRequest(string $endpoint): array
    {
        return $this->makeRequest('DELETE', $endpoint);
    }

    /**
     * Récupérer le token d'authentification depuis la session.
     */
    protected function getAuthToken(): ?string
    {
        $user = $this->session->get('user');

        return $user['token'] ?? null;
    }

    /**
     * Récupérer l'ID de l'utilisateur courant.
     */
    protected function getCurrentUserId(): ?int
    {
        $user = $this->session->get('user');

        return $user['id'] ?? 2;
    }

    /**
     * Récupérer l'ID du magasin courant.
     */
    protected function getCurrentShopId(): ?int
    {
        $user = $this->session->get('user');

        return $user['shop_id'] ?? 3;
    }

    /**
     * Vérifier si l'utilisateur est authentifié.
     */
    public function isAuthenticated(): bool
    {
        $user = $this->session->get('user');

        return !empty($user) && !empty($user['token']);
    }

    /**
     * Récupérer les données de l'utilisateur courant.
     */
    public function getCurrentUser(): ?array
    {
        return $this->session->get('user');
    }
}
