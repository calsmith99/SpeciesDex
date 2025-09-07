<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class SpeciesSearchController extends Controller
{
    public function search(Request $request)
    {
        $request->validate([
            'query' => 'required|string|max:255',
        ]);

        $query = $request->input('query');
        
        // Query GBIF API for species information
        $response = Http::get(
            'https://api.gbif.org/v1/species/search', 
            [
                'q' => $query,
                'limit' => 10
            ]
        );
        
        if (!$response->ok()) {
            return response()->json(
                [
                    'error' => 'GBIF API error',
                    'details' => $response->json() ?? 'No response details',
                    'status' => $response->status()
                ], 
                500
            );
        }
        
        $results = $response->json()['results'] ?? [];
        
        // Format the results for frontend display
        $formattedResults = [];
        foreach ($results as $result) {
            if (isset($result['scientificName']) && isset($result['taxonomicStatus'])) {
                $formattedResults[] = [
                    'scientific_name' => $result['scientificName'],
                    'taxonomic_status' => $result['taxonomicStatus'],
                    'rank' => $result['rank'] ?? 'unknown',
                    'kingdom' => $result['kingdom'] ?? 'unknown',
                    'phylum' => $result['phylum'] ?? 'unknown',
                    'class' => $result['class'] ?? 'unknown',
                    'order' => $result['order'] ?? 'unknown',
                    'family' => $result['family'] ?? 'unknown',
                    'genus' => $result['genus'] ?? 'unknown',
                    'species' => $result['species'] ?? 'unknown',
                    'gbif_key' => $result['key'] ?? null
                ];
            }
        }
        
        return response()->json([
            'success' => true,
            'results' => $formattedResults
        ]);
    }
}
