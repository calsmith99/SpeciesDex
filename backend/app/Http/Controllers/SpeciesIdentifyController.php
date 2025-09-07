<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SpeciesIdentifyController extends Controller
{
    // Common taxonomic ranks in descending order of specificity
    private $taxonomicRanks = [
        'domain', 'kingdom', 'phylum', 'class', 'order', 'family', 'genus', 'species'
    ];
    
    // Common biological structure terms that indicate body parts
    private $structurePatterns = [
        'of a', 'of an', 'of the', 'part', 'structure', 'anatomy', 'system',
        'eye', 'wing', 'leg', 'tail', 'head', 'body', 'foot', 'beak', 'fin'
    ];

    // Cache for species data to avoid repeated API calls
    private $speciesCache = [];

    /**
     * Main method for identifying species from an image
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function identify(Request $request)
    {
        Log::info('=== SPECIES IDENTIFICATION STARTED ===');
        
        $request->validate(
            [
                'image' => 'required|image|max:4096',
            ]
        );
        
        Log::info('Image validation passed', [
            'file_size' => $request->file('image')->getSize(),
            'mime_type' => $request->file('image')->getMimeType()
        ]);
        
        // Step 1: Process the image with Google Cloud Vision API
        $imageData = base64_encode(file_get_contents($request->file('image')->getRealPath()));
        Log::info('Image encoded for Vision API', ['data_length' => strlen($imageData)]);
        
        $detections = $this->detectImageContent($imageData);
        
        if (isset($detections['error'])) {
            Log::error('Vision API returned error', $detections);
            return response()->json($detections, 500);
        }
        
        Log::info('Vision API detections received', [
            'detection_count' => count($detections),
            'top_5_detections' => array_slice($detections, 0, 5)
        ]);
        
        // Step 2: Filter detections to get clean species names for selection
        $speciesOptions = $this->getSpeciesOptionsFromDetections($detections);
        
        Log::info('Species options generated', [
            'options_count' => count($speciesOptions),
            'options' => $speciesOptions
        ]);
        
        // Step 3: Return detections and species options for user selection
        Log::info('=== SPECIES IDENTIFICATION COMPLETED ===', [
            'total_detections' => count($detections),
            'species_options' => count($speciesOptions)
        ]);
        
        return response()->json(
            [
                'success' => true,
                'detections' => $detections,
                'species_options' => $speciesOptions
            ]
        );
    }
    
    /**
     * Process an image with Google Cloud Vision API
     *
     * @param string $imageData Base64-encoded image data
     * @return array Array of detections or error
     */
    private function detectImageContent(string $imageData): array
    {
        Log::info('=== VISION API PROCESSING STARTED ===', ['image_data_length' => strlen($imageData)]);
        
        $apiKey = env('GOOGLE_CLOUD_VISION_API_KEY');
        
        // Check if API key exists
        if (!$apiKey) {
            Log::error('Google Cloud Vision API key not found in environment');
            return ['error' => 'Google Cloud Vision API key not found in environment'];
        }
        
        Log::info('API key found, preparing Vision API request');
        
        // Vision API URL
        $visionUrl = 'https://vision.googleapis.com/v1/images:annotate?key=' . $apiKey;
        
        $payload = [
            'requests' => [[
                'image' => ['content' => $imageData],
                'features' => [
                    ['type' => 'LABEL_DETECTION', 'maxResults' => 20],
                    ['type' => 'OBJECT_LOCALIZATION', 'maxResults' => 10]
                ],
            ]]
        ];
        
        Log::info('Sending request to Vision API', [
            'url' => $visionUrl,
            'features' => ['LABEL_DETECTION (max 20)', 'OBJECT_LOCALIZATION (max 10)']
        ]);
        
        $response = Http::post($visionUrl, $payload);
        
        Log::info('Vision API response received', [
            'status_code' => $response->status(),
            'response_ok' => $response->ok(),
            'response_size' => strlen($response->body())
        ]);
        
        if (!$response->ok()) {
            Log::error('Vision API request failed', [
                'status' => $response->status(),
                'response_body' => $response->body(),
                'response_json' => $response->json()
            ]);
            return [
                'error' => 'Vision API error',
                'details' => $response->json() ?? 'No response details',
                'status' => $response->status()
            ];
        }
        
        $processedResults = $this->processVisionApiResponse($response->json());
        
        Log::info('=== VISION API PROCESSING COMPLETED ===', [
            'processed_detections_count' => count($processedResults)
        ]);
        
        return $processedResults;
    }
    
    /**
     * Process the raw Vision API response into a clean detection list
     *
     * @param array $apiResponseData Raw API response data
     * @return array Formatted list of detections
     */
    private function processVisionApiResponse(array $apiResponseData): array
    {
        $apiResponse = $apiResponseData['responses'][0] ?? [];
        $labels = $apiResponse['labelAnnotations'] ?? [];
        $objects = $apiResponse['localizedObjectAnnotations'] ?? [];
        
        // Combine labels and objects into a single array
        $allDetections = [];
        
        // Add labels
        foreach ($labels as $label) {
            $allDetections[] = [
                'description' => $label['description'],
                'score' => $label['score'],
                'mid' => $label['mid'] ?? '',
                'source' => 'label_detection'
            ];
        }
        
        // Add objects if they aren't already in the labels
        foreach ($objects as $object) {
            $found = false;
            foreach ($allDetections as $detection) {
                if (strtolower($detection['description']) === strtolower($object['name'])) {
                    $found = true;
                    break;
                }
            }
            
            if (!$found) {
                $allDetections[] = [
                    'description' => $object['name'],
                    'score' => $object['score'],
                    'mid' => $object['mid'] ?? '',
                    'source' => 'object_detection'
                ];
            }
        }
        
        // Sort by confidence score (highest first)
        usort(
            $allDetections, 
            function ($a, $b) {
                return $b['score'] <=> $a['score'];
            }
        );
        
        return $allDetections;
    }
    
    /**
     * Selects the best species query from detected objects and labels
     * 
     * @param array $detections Array of detected objects and labels
     * @return string|null The best query string or null if no suitable query found
     */
    private function getBestSpeciesQuery(array $detections): ?string
    {
        if (empty($detections)) {
            return null;
        }

        Log::info('Selecting best species query from detections', [
            'detection_count' => count($detections),
            'top_detections' => array_slice($detections, 0, 10)
        ]);
        
        // Define terms to filter out (body parts, generic categories, colors, etc.)
        $filterOutTerms = [
            // Body parts
            'beak', 'wing', 'tail', 'head', 'eye', 'leg', 'foot', 'feather', 'claw',
            // Generic animal categories
            'bird', 'vertebrate', 'animal', 'wildlife', 'terrestrial animal',
            // Generic descriptors
            'grey', 'gray', 'black', 'white', 'brown', 'red', 'blue', 'green',
            // Environmental terms
            'twig', 'branch', 'tree', 'nature', 'outdoor',
            // Broad taxonomic groups (too general)
            'songbirds', 'passerine', 'old world flycatchers'
        ];
        
        // Filter out unwanted terms
        $validDetections = array_filter($detections, function($detection) use ($filterOutTerms) {
            $desc = strtolower(trim($detection['description']));
            
            // Filter out terms that are too generic or are body parts
            foreach ($filterOutTerms as $term) {
                if ($desc === $term || strpos($desc, $term) !== false) {
                    return false;
                }
            }
            
            return true;
        });
        
        Log::info('Valid detections after filtering', [
            'valid_count' => count($validDetections),
            'valid_detections' => array_values($validDetections)
        ]);
        
        if (empty($validDetections)) {
            Log::warning('No valid detections after filtering, using original top result');
            return $detections[0]['description'] ?? null;
        }
        
        // Sort valid detections by score (highest first)
        usort($validDetections, function($a, $b) {
            return $b['score'] <=> $a['score'];
        });
        
        // Priority 1: Look for specific species names (containing species-specific terms)
        $speciesTerms = [
            'magpie', 'crow', 'jay', 'hawk', 'eagle', 'sparrow', 'robin', 'cardinal',
            'woodpecker', 'owl', 'pigeon', 'dove', 'finch', 'warbler', 'thrush',
            'wren', 'swallow', 'martin', 'flycatcher', 'vireo', 'tanager', 'bunting'
        ];
        
        foreach ($validDetections as $detection) {
            $desc = strtolower($detection['description']);
            foreach ($speciesTerms as $term) {
                if (strpos($desc, $term) !== false) {
                    Log::info("Found species-specific term: {$detection['description']}");
                    return $detection['description'];
                }
            }
        }
        
        // Priority 2: Look for binomial nomenclature (Genus species)
        foreach ($validDetections as $detection) {
            $desc = $detection['description'];
            if (preg_match('/^[A-Z][a-z]+ [a-z]+$/', $desc)) {
                Log::info("Found binomial nomenclature: {$desc}");
                return $desc;
            }
        }
        
        // Priority 3: Avoid family-level terms, use the highest scoring remaining detection
        foreach ($validDetections as $detection) {
            $desc = strtolower($detection['description']);
            if (strpos($desc, 'family') === false && strpos($desc, 'order') === false) {
                Log::info("Using highest scoring non-family detection: {$detection['description']}");
                return $detection['description'];
            }
        }
        
        // Fallback: Use the top valid detection
        $topValid = reset($validDetections);
        Log::info("Using fallback detection: {$topValid['description']}");
        return $topValid['description'];
    }
    
    /**
     * Extract clean species options from detections for user selection
     * 
     * @param array $detections Array of detected objects and labels
     * @return array Array of species options for user selection
     */
    private function getSpeciesOptionsFromDetections(array $detections): array
    {
        if (empty($detections)) {
            return [];
        }

        Log::info('Extracting species options from detections', [
            'detection_count' => count($detections),
            'detections' => array_slice($detections, 0, 10)
        ]);
        
        // Define terms to filter out (body parts, generic categories, colors, etc.)
        $filterOutTerms = [
            // Body parts
            'beak', 'wing', 'tail', 'head', 'eye', 'leg', 'foot', 'feather', 'claw',
            // Generic animal categories
            'bird', 'vertebrate', 'animal', 'wildlife', 'terrestrial animal',
            // Generic descriptors
            'grey', 'gray', 'black', 'white', 'brown', 'red', 'blue', 'green',
            // Environmental terms
            'twig', 'branch', 'tree', 'nature', 'outdoor',
            // Broad taxonomic groups (too general)
            'songbirds', 'passerine', 'old world flycatchers'
        ];
        
        // Filter out unwanted terms
        $validDetections = array_filter($detections, function($detection) use ($filterOutTerms) {
            $desc = strtolower(trim($detection['description']));
            
            // Filter out terms that are too generic or are body parts
            foreach ($filterOutTerms as $term) {
                if ($desc === $term || strpos($desc, $term) !== false) {
                    return false;
                }
            }
            
            return true;
        });
        
        // Sort valid detections by score (highest first)
        usort($validDetections, function($a, $b) {
            return $b['score'] <=> $a['score'];
        });
        
        // Extract species names, prioritizing specific bird species
        $speciesOptions = [];
        $speciesTerms = [
            'magpie', 'crow', 'jay', 'hawk', 'eagle', 'sparrow', 'robin', 'cardinal',
            'woodpecker', 'owl', 'pigeon', 'dove', 'finch', 'warbler', 'thrush',
            'wren', 'swallow', 'martin', 'flycatcher', 'vireo', 'tanager', 'bunting'
        ];
        
        // Priority 1: Look for specific species names
        foreach ($validDetections as $detection) {
            $desc = strtolower($detection['description']);
            foreach ($speciesTerms as $term) {
                if (strpos($desc, $term) !== false) {
                    $speciesOptions[] = [
                        'name' => $detection['description'],
                        'score' => $detection['score'],
                        'source' => 'species_detection'
                    ];
                }
            }
        }
        
        // Priority 2: Add other valid detections (avoiding family terms)
        foreach ($validDetections as $detection) {
            $desc = strtolower($detection['description']);
            if (strpos($desc, 'family') === false && strpos($desc, 'order') === false) {
                // Avoid duplicates
                $alreadyAdded = false;
                foreach ($speciesOptions as $existing) {
                    if (strtolower($existing['name']) === $desc) {
                        $alreadyAdded = true;
                        break;
                    }
                }
                
                if (!$alreadyAdded) {
                    $speciesOptions[] = [
                        'name' => $detection['description'],
                        'score' => $detection['score'],
                        'source' => 'general_detection'
                    ];
                }
            }
        }
        
        // Limit to top 8 options to avoid overwhelming the user
        $speciesOptions = array_slice($speciesOptions, 0, 8);
        
        // Fetch images for each species option
        $speciesOptionsWithImages = $this->_addImagesToSpeciesOptions($speciesOptions);
        
        Log::info('Species options extracted with images', [
            'option_count' => count($speciesOptionsWithImages),
            'options' => $speciesOptionsWithImages
        ]);
        
        return $speciesOptionsWithImages;
    }
    
    /**
     * Add images to species options by searching for each species
     */
    private function _addImagesToSpeciesOptions(array $speciesOptions): array
    {
        foreach ($speciesOptions as &$option) {
            $speciesName = $option['name'];
            
            // First try to get a quick GBIF match to get a species key
            try {
                $gbifResponse = Http::timeout(3)->get(
                    'https://api.gbif.org/v1/species/search',
                    [
                        'q' => $speciesName,
                        'limit' => 1
                    ]
                );
                
                if ($gbifResponse->ok()) {
                    $results = $gbifResponse->json()['results'] ?? [];
                    if (!empty($results) && isset($results[0]['key'])) {
                        $gbifKey = $results[0]['key'];
                        
                        // Try to fetch an image using our multi-source approach
                        $imageUrl = $this->_fetchImageFromGbif($gbifKey);
                        if (!$imageUrl) {
                            $imageUrl = $this->_fetchImageFromINaturalist($speciesName);
                        }
                        if (!$imageUrl) {
                            $imageUrl = $this->_fetchImageFromWikipedia($gbifKey);
                        }
                        if (!$imageUrl) {
                            // Try a simple web search approach for common species
                            $imageUrl = $this->_fetchImageFromSimpleSearch($speciesName);
                        }
                        
                        if ($imageUrl) {
                            $option['image'] = $imageUrl;
                            Log::debug("Found image for {$speciesName}: {$imageUrl}");
                        } else {
                            $option['image'] = null;
                            Log::debug("No image found for {$speciesName}");
                        }
                    }
                }
            } catch (\Exception $e) {
                Log::warning("Error fetching image for {$speciesName}: {$e->getMessage()}");
                $option['image'] = null;
            }
        }
        
        return $speciesOptions;
    }
    
    /**
     * Simple image search for common species names
     */
    private function _fetchImageFromSimpleSearch(string $speciesName): ?string
    {
        // For common species, we can try a direct approach
        // This is a fallback when GBIF/iNaturalist don't have images
        
        // We could implement a simple web image search here if needed
        // For now, return null and rely on the other sources
        return null;
    }
    
    /**
     * Get detailed species information from GBIF for a selected species
     * Uses a two-step search process for complete classification
     */
    public function getSpeciesDetails(Request $request)
    {
        Log::info('=== ENHANCED SPECIES SEARCH STARTED ===');
        
        $request->validate(
            [
                'species_name' => 'required|string'
            ]
        );
        
        $speciesName = $request->input('species_name');
        
        Log::info("Getting enhanced species details for: {$speciesName}");
        
        // Step 1: Search for the species to get the canonical name and key
        $speciesResults = $this->_searchSpeciesWithCanonicalNameLookup($speciesName);
        
        if (!$speciesResults) {
            Log::warning("No species results found for: {$speciesName}");
            return response()->json(
                [
                    'success' => false,
                    'message' => 'No species information found'
                ],
                404
            );
        }
        
        Log::info('=== ENHANCED SPECIES SEARCH COMPLETED ===', [
            'species_name' => $speciesName,
            'results_count' => count($speciesResults),
            'first_result' => $speciesResults[0] ?? null
        ]);
        
        return response()->json(
            [
                'success' => true,
                'species_name' => $speciesName,
                'species_results' => $speciesResults
            ]
        );
    }

    /**
     * Enhanced species search using canonical name lookup for classification
     */
    private function _searchSpeciesWithCanonicalNameLookup(string $speciesName): ?array
    {
        Log::info('=== CANONICAL NAME LOOKUP SEARCH STARTED ===', ['species_name' => $speciesName]);
        
        try {
            // Check if this looks like a common name (contains spaces and non-Latin characters)
            $isLikelyCommonName = $this->_isLikelyCommonName($speciesName);
            
            if ($isLikelyCommonName) {
                Log::info("Species name appears to be a common name, trying alternative search strategies", [
                    'species_name' => $speciesName
                ]);
                
                // Try to find scientific name using alternative strategies
                $scientificName = $this->_findScientificNameFromCommonName($speciesName);
                if ($scientificName) {
                    Log::info("Found scientific name for common name", [
                        'common_name' => $speciesName,
                        'scientific_name' => $scientificName
                    ]);
                    // Search again with the scientific name
                    return $this->_searchSpeciesWithCanonicalNameLookup($scientificName);
                }
            }
            
            Log::info("Step 1: Initial search for species: {$speciesName}");
            
            // Step 1: Initial search to get canonical name and key
            $initialResponse = Http::timeout(10)->get(
                'https://api.gbif.org/v1/species/search',
                [
                    'q' => $speciesName,
                    'limit' => 10,  // Increased limit to find better matches
                    'rank' => 'SPECIES' // Only look for species-level results
                ]
            );
            
            Log::info('Initial GBIF search response', [
                'status_code' => $initialResponse->status(),
                'response_ok' => $initialResponse->ok(),
                'url' => 'https://api.gbif.org/v1/species/search',
                'params' => ['q' => $speciesName, 'limit' => 1]
            ]);
            
            if (!$initialResponse->ok()) {
                Log::error("Initial GBIF search failed for: {$speciesName}", [
                    'status' => $initialResponse->status(),
                    'body' => $initialResponse->body()
                ]);
                return null;
            }
            
            $initialResults = $initialResponse->json()['results'] ?? [];
            Log::info('Initial search results', [
                'results_count' => count($initialResults),
                'results' => $initialResults
            ]);
            
            if (empty($initialResults)) {
                Log::warning("No initial results found for: {$speciesName}");
                return null;
            }
            
            $firstResult = $initialResults[0];
            $canonicalName = $firstResult['canonicalName'] ?? 
                           $firstResult['scientificName'] ?? null;
            $gbifKey = $firstResult['key'] ?? null;
            
            Log::info('Extracted from first result', [
                'canonical_name' => $canonicalName,
                'gbif_key' => $gbifKey,
                'scientific_name' => $firstResult['scientificName'] ?? 'N/A',
                'taxonomic_status' => $firstResult['taxonomicStatus'] ?? 'N/A'
            ]);
            
            if (!$canonicalName) {
                Log::warning("No canonical name found for: {$speciesName}");
                return $this->formatGbifResults($initialResults);
            }
            
            Log::info("Step 2: Searching with canonical name: {$canonicalName}");
            
            // Step 2: Search again using canonical name for complete data
            $canonicalResponse = Http::timeout(10)->get(
                'https://api.gbif.org/v1/species/search',
                [
                    'q' => $canonicalName,
                    'limit' => 10,
                    'rank' => 'SPECIES' // Only look for species-level results
                ]
            );
            
            Log::info('Canonical name search response', [
                'status_code' => $canonicalResponse->status(),
                'response_ok' => $canonicalResponse->ok(),
                'canonical_name' => $canonicalName
            ]);
            
            if (!$canonicalResponse->ok()) {
                Log::error("Canonical name search failed for: {$canonicalName}", [
                    'status' => $canonicalResponse->status(),
                    'body' => $canonicalResponse->body()
                ]);
                return $this->formatGbifResults($initialResults);
            }
            
            $canonicalResults = $canonicalResponse->json()['results'] ?? [];
            Log::info('Canonical search results', [
                'results_count' => count($canonicalResults),
                'first_3_results' => array_slice($canonicalResults, 0, 3)
            ]);
            
            if (empty($canonicalResults)) {
                Log::warning("No canonical results found for: {$canonicalName}");
                return $this->formatGbifResults($initialResults);
            }
            
            // Find the best match with the most complete taxonomic data
            $bestMatch = $this->_findBestTaxonomicMatch(
                $canonicalResults, 
                $gbifKey, 
                $canonicalName
            );
            
            if (!$bestMatch) {
                Log::warning("No suitable match found for: {$canonicalName}");
                return $this->formatGbifResults($initialResults);
            }
            
            Log::info("Found best match: {$bestMatch['scientificName']}", [
                'key' => $bestMatch['key'] ?? 'N/A',
                'taxonomic_status' => $bestMatch['taxonomicStatus'] ?? 'N/A',
                'rank' => $bestMatch['rank'] ?? 'N/A'
            ]);
            
            // Format and enrich the results
            $formattedResults = $this->formatGbifResults([$bestMatch]);
            Log::info('Formatted results', ['formatted_count' => count($formattedResults)]);
            
            // Apply taxonomic enrichment to fill missing classification layers
            $enrichedResults = $this->_enrichTaxonomicData($formattedResults);
            Log::info('Enriched results', ['enriched_count' => count($enrichedResults)]);
            
            Log::info(
                "=== CANONICAL NAME LOOKUP SEARCH COMPLETED ===",
                [
                    'original_query' => $speciesName,
                    'canonical_name' => $canonicalName,
                    'final_result_count' => count($enrichedResults),
                    'final_result' => $enrichedResults[0] ?? null
                ]
            );
            
            return $enrichedResults;
            
        } catch (\Exception $e) {
            Log::error("Exception in enhanced search for {$speciesName}: {$e->getMessage()}", [
                'exception_class' => get_class($e),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString()
            ]);
            // Fallback to simple search
            return $this->searchSpeciesInformation($speciesName);
        }
    }

    /**
     * Check if a species name is likely a common name rather than scientific name
     *
     * @param string $speciesName
     * @return bool
     */
    private function _isLikelyCommonName(string $speciesName): bool
    {
        // Scientific names are typically two words (Genus species) and Latin
        // Common names often have more words, capitals, or English words
        
        $words = explode(' ', trim($speciesName));
        
        // If more than 2 words, likely a common name
        if (count($words) > 2) {
            return true;
        }
        
        // If it contains obvious common name indicators
        $commonIndicators = [
            'american', 'european', 'african', 'asian', 'red', 'blue', 'green', 
            'yellow', 'black', 'white', 'brown', 'grey', 'gray', 'large', 'small',
            'common', 'lesser', 'greater', 'northern', 'southern', 'eastern', 'western'
        ];
        
        $lowerName = strtolower($speciesName);
        foreach ($commonIndicators as $indicator) {
            if (strpos($lowerName, $indicator) !== false) {
                return true;
            }
        }
        
        // If the first word is not capitalized like a genus name would be
        if (count($words) >= 2) {
            $firstWord = $words[0];
            if (!ctype_upper($firstWord[0]) || ctype_upper($firstWord[1] ?? '')) {
                return true;
            }
        }
        
        return false;
    }

    /**
     * Try to find the scientific name from a common name using various strategies
     *
     * @param string $commonName
     * @return string|null
     */
    private function _findScientificNameFromCommonName(string $commonName): ?string
    {
        Log::info("Attempting to find scientific name for common name: {$commonName}");
        
        // Strategy 1: Common name mappings for well-known species
        $commonNameMappings = [
            'american robin' => 'Turdus migratorius',
            'robin' => 'Turdus migratorius',
            'house sparrow' => 'Passer domesticus',
            'european starling' => 'Sturnus vulgaris',
            'rock pigeon' => 'Columba livia',
            'mourning dove' => 'Zenaida macroura',
            'blue jay' => 'Cyanocitta cristata',
            'northern cardinal' => 'Cardinalis cardinalis',
            'eurasian magpie' => 'Pica pica',
            'magpie' => 'Pica pica',
            'red-winged blackbird' => 'Agelaius phoeniceus',
            'common grackle' => 'Quiscalus quiscula',
            'song sparrow' => 'Melospiza melodia',
            'white-breasted nuthatch' => 'Sitta carolinensis',
            'downy woodpecker' => 'Picoides pubescens',
            'northern mockingbird' => 'Mimus polyglottos',
            'american goldfinch' => 'Spinus tristis',
            'house finch' => 'Haemorhous mexicanus',
            'dark-eyed junco' => 'Junco hyemalis',
            'carolina wren' => 'Thryothorus ludovicianus',
            'northern flicker' => 'Colaptes auratus',
            'red-tailed hawk' => 'Buteo jamaicensis',
            'cooper\'s hawk' => 'Accipiter cooperii',
            'sharp-shinned hawk' => 'Accipiter striatus',
            'great blue heron' => 'Ardea herodias',
            'mallard' => 'Anas platyrhynchos',
            'canada goose' => 'Branta canadensis',
            'turkey vulture' => 'Cathartes aura',
            'peregrine falcon' => 'Falco peregrinus',
            'bald eagle' => 'Haliaeetus leucocephalus',
            'great horned owl' => 'Bubo virginianus',
            'barred owl' => 'Strix varia',
            'screech owl' => 'Megascops asio',
            'ruby-throated hummingbird' => 'Archilochus colubris',
            'belted kingfisher' => 'Megaceryle alcyon',
            'pileated woodpecker' => 'Dryocopus pileatus',
            'hairy woodpecker' => 'Picoides villosus',
            'yellow warbler' => 'Setophaga petechia',
            'american crow' => 'Corvus brachyrhynchos',
            'fish crow' => 'Corvus ossifragus',
            'common raven' => 'Corvus corax',
            'tufted titmouse' => 'Baeolophus bicolor',
            'black-capped chickadee' => 'Poecile atricapillus',
            'carolina chickadee' => 'Poecile carolinensis',
            'white-throated sparrow' => 'Zonotrichia albicollis',
            'eastern bluebird' => 'Sialia sialis',
            'brown thrasher' => 'Toxostoma rufum',
            'cedar waxwing' => 'Bombycilla cedrorum',
            'barn swallow' => 'Hirundo rustica',
            'tree swallow' => 'Tachycineta bicolor',
            'purple martin' => 'Progne subis',
            'chimney swift' => 'Chaetura pelagica',
            'ruby-crowned kinglet' => 'Regulus calendula',
            'golden-crowned kinglet' => 'Regulus satrapa',
            'brown creeper' => 'Certhia americana',
            'house wren' => 'Troglodytes aedon',
            'winter wren' => 'Troglodytes hiemalis',
            'marsh wren' => 'Cistothorus palustris',
            'sedge wren' => 'Cistothorus stellaris',
        ];
        
        $lowerCommonName = strtolower(trim($commonName));
        if (isset($commonNameMappings[$lowerCommonName])) {
            Log::info("Found scientific name using mapping", [
                'common_name' => $commonName,
                'scientific_name' => $commonNameMappings[$lowerCommonName]
            ]);
            return $commonNameMappings[$lowerCommonName];
        }
        
        // Strategy 2: Try searching GBIF with better filtering
        try {
            $response = Http::timeout(10)->get('https://api.gbif.org/v1/species/search', [
                'q' => $commonName,
                'limit' => 50,
                'rank' => 'SPECIES'
            ]);
            
            if ($response->ok()) {
                $results = $response->json()['results'] ?? [];
                
                foreach ($results as $result) {
                    // Look for vernacular names that match our common name
                    if (isset($result['vernacularNames']) && !empty($result['vernacularNames'])) {
                        foreach ($result['vernacularNames'] as $vernacular) {
                            $vernacularName = strtolower($vernacular['vernacularName'] ?? '');
                            if ($vernacularName === $lowerCommonName) {
                                Log::info("Found scientific name via vernacular name match", [
                                    'common_name' => $commonName,
                                    'scientific_name' => $result['scientificName'],
                                    'matched_vernacular' => $vernacular['vernacularName']
                                ]);
                                return $result['scientificName'];
                            }
                        }
                    }
                    
                    // Also check if the scientific name contains our search term meaningfully
                    if (isset($result['scientificName']) && 
                        isset($result['rank']) && 
                        $result['rank'] === 'SPECIES') {
                        
                        $scientificName = $result['scientificName'];
                        
                        // For bird names, avoid obvious false matches
                        if (strpos(strtolower($scientificName), 'virus') === false &&
                            strpos(strtolower($scientificName), 'bacteria') === false) {
                            
                            // Use the first species-level result as a fallback
                            if (!isset($fallbackResult)) {
                                $fallbackResult = $scientificName;
                            }
                        }
                    }
                }
                
                // If we found a fallback result, use it
                if (isset($fallbackResult)) {
                    Log::info("Using fallback scientific name", [
                        'common_name' => $commonName,
                        'scientific_name' => $fallbackResult
                    ]);
                    return $fallbackResult;
                }
            }
        } catch (\Exception $e) {
            Log::warning("Error searching for scientific name: {$e->getMessage()}");
        }
        
        Log::warning("Could not find scientific name for common name: {$commonName}");
        return null;
    }
    
    /**
     * Find the best taxonomic match from search results
     */
    private function _findBestTaxonomicMatch(
        array $results, 
        ?int $preferredKey, 
        string $canonicalName
    ): ?array {
        // Priority 1: Exact key match
        if ($preferredKey) {
            foreach ($results as $result) {
                if (isset($result['key']) && $result['key'] === $preferredKey) {
                    Log::info("Found exact key match: {$result['scientificName']}");
                    return $result;
                }
            }
        }
        
        // Priority 2: Exact canonical name match with ACCEPTED status
        foreach ($results as $result) {
            if (isset($result['canonicalName']) && 
                strtolower($result['canonicalName']) === strtolower($canonicalName) &&
                isset($result['taxonomicStatus']) && 
                $result['taxonomicStatus'] === 'ACCEPTED') {
                Log::info("Found accepted canonical name match: {$result['scientificName']}");
                return $result;
            }
        }
        
        // Priority 3: Exact canonical name match (any status)
        foreach ($results as $result) {
            if (isset($result['canonicalName']) && 
                strtolower($result['canonicalName']) === strtolower($canonicalName)) {
                Log::info("Found canonical name match: {$result['scientificName']}");
                return $result;
            }
        }
        
        // Priority 4: Scientific name match with ACCEPTED status
        foreach ($results as $result) {
            if (isset($result['scientificName']) && 
                strtolower($result['scientificName']) === strtolower($canonicalName) &&
                isset($result['taxonomicStatus']) && 
                $result['taxonomicStatus'] === 'ACCEPTED') {
                Log::info("Found accepted scientific name match: {$result['scientificName']}");
                return $result;
            }
        }
        
        // Priority 5: Best result with most complete taxonomy
        $speciesLevelResults = array_filter($results, function ($result) {
            return isset($result['rank']) && strtoupper($result['rank']) === 'SPECIES';
        });
        
        if (!empty($speciesLevelResults)) {
            // Score results based on taxonomic completeness
            $scoredResults = [];
            foreach ($speciesLevelResults as $result) {
                $score = $this->_calculateTaxonomicCompletenessScore($result);
                $scoredResults[] = ['result' => $result, 'score' => $score];
            }
            
            // Sort by score (highest first)
            usort($scoredResults, function ($a, $b) {
                return $b['score'] <=> $a['score'];
            });
            
            if (!empty($scoredResults)) {
                $bestResult = $scoredResults[0]['result'];
                $bestScore = $scoredResults[0]['score'];
                Log::info("Found best completeness match: {$bestResult['scientificName']} (score: {$bestScore})");
                return $bestResult;
            }
        }
        
        // Fallback: First result
        if (!empty($results)) {
            Log::info("Using fallback result: {$results[0]['scientificName']}");
            return $results[0];
        }
        
        return null;
    }
    
    /**
     * Calculate a completeness score for taxonomic data
     */
    private function _calculateTaxonomicCompletenessScore(array $result): int
    {
        $score = 0;
        
        // Base score for having a result
        $score += 1;
        
        // Bonus for ACCEPTED taxonomic status
        if (isset($result['taxonomicStatus']) && $result['taxonomicStatus'] === 'ACCEPTED') {
            $score += 5;
        }
        
        // Bonus for species rank
        if (isset($result['rank']) && strtoupper($result['rank']) === 'SPECIES') {
            $score += 3;
        }
        
        // Points for each taxonomic level present
        $taxonomicLevels = ['kingdom', 'phylum', 'class', 'order', 'family', 'genus', 'species'];
        foreach ($taxonomicLevels as $level) {
            if (isset($result[$level]) && !empty($result[$level]) && $result[$level] !== 'unknown') {
                $score += 1;
            }
        }
        
        return $score;
    }
    
    /**
     * Search for species information using the GBIF API
     *
     * @param string $query The search query (usually a species name)
     * @return array|null Array of species information or null if not found
     */
    private function searchSpeciesInformation(string $query): ?array
    {
        $gbifResponse = Http::get(
            'https://api.gbif.org/v1/species/search',
            [
                'q' => $query,
                'limit' => 5
            ]
        );
        
        if (!$gbifResponse->ok()) {
            return null;
        }
        
        $results = $gbifResponse->json()['results'] ?? [];
        return $this->formatGbifResults($results);
    }
    
    /**
     * Format GBIF API results into a standardized structure
     *
     * @param array $results Raw results from GBIF API
     * @return array Formatted results
     */
    private function formatGbifResults(array $results): array
    {
        $formatted = [];
        $speciesKeys = [];
        $speciesMap = []; // For deduplication
        
        // First pass: collect all species and their keys
        foreach ($results as $result) {
            // Only include species-level results
            if (isset($result['scientificName']) && 
                isset($result['rank']) &&
                strtoupper($result['rank']) === 'SPECIES'
            ) {
                // Use enrichment to fill in missing taxonomic data from other search results
                $enrichedData = $this->_enrichTaxonomicDataFromSearchResults($result, $results);
                
                // Check if this is a synonym and resolve it if possible
                $taxonomicStatus = $enrichedData['taxonomicStatus'] ?? $result['taxonomicStatus'] ?? 'unknown';
                $resolvedData = $enrichedData;
                
                if (in_array(strtoupper($taxonomicStatus), ['SYNONYM', 'HOMOTYPIC_SYNONYM', 'HETEROTYPIC_SYNONYM'])) {
                    Log::info("Found synonym, attempting to resolve", [
                        'synonym_name' => $result['scientificName'],
                        'taxonomic_status' => $taxonomicStatus
                    ]);
                    
                    $acceptedData = $this->_resolveSynonymToAcceptedName($result);
                    if ($acceptedData) {
                        Log::info("Successfully resolved synonym", [
                            'synonym_name' => $result['scientificName'],
                            'accepted_name' => $acceptedData['scientificName']
                        ]);
                        $resolvedData = $acceptedData;
                    }
                }
                
                $speciesData = [
                    'scientific_name' => $resolvedData['scientificName'] ?? $result['scientificName'],
                    'taxonomic_status' => $resolvedData['taxonomicStatus'] ?? $result['taxonomicStatus'] ?? 'unknown',
                    'rank' => $resolvedData['rank'] ?? $result['rank'] ?? 'unknown',
                    'domain' => strtolower($resolvedData['domain'] ?? $result['domain'] ?? 'eukaryota'),
                    'kingdom' => strtolower($resolvedData['kingdom'] ?? $result['kingdom'] ?? 'unknown'),
                    'phylum' => strtolower($resolvedData['phylum'] ?? $result['phylum'] ?? 'unknown'),
                    'class' => strtolower($resolvedData['class'] ?? $result['class'] ?? 'unknown'),
                    'order' => strtolower($resolvedData['order'] ?? $result['order'] ?? 'unknown'),
                    'family' => strtolower($resolvedData['family'] ?? $result['family'] ?? 'unknown'),
                    'genus' => strtolower($resolvedData['genus'] ?? $result['genus'] ?? 'unknown'),
                    'species' => strtolower($resolvedData['species'] ?? $result['species'] ?? 'unknown'),
                    'gbif_key' => $resolvedData['key'] ?? $result['key'] ?? null,
                    'preferred_common_name' => $this->_extractPreferredCommonName($resolvedData, $result),
                    'reference_image' => null,
                    'image_source' => null,
                    'synonym_of' => ($taxonomicStatus !== 'ACCEPTED' && isset($acceptedData)) ? $result['scientificName'] : null
                ];
                
                // Create a normalized species identifier for deduplication
                // Remove author citations and normalize the name
                $normalizedName = $this->_normalizeSpeciesName($speciesData['scientific_name']);
                
                // Check if we already have this species
                if (isset($speciesMap[$normalizedName])) {
                    // Keep the one with better taxonomic status
                    $existing = $speciesMap[$normalizedName];
                    $current = $speciesData;
                    
                    // Priority: ACCEPTED > DOUBTFUL > SYNONYM > unknown
                    $statusPriority = [
                        'ACCEPTED' => 4,
                        'DOUBTFUL' => 3,
                        'SYNONYM' => 2,
                        'HOMOTYPIC_SYNONYM' => 2,
                        'HETEROTYPIC_SYNONYM' => 2,
                        'unknown' => 1
                    ];
                    
                    $existingPriority = $statusPriority[$existing['taxonomic_status']] ?? 1;
                    $currentPriority = $statusPriority[$current['taxonomic_status']] ?? 1;
                    
                    // Keep the one with higher priority, or the first one if equal
                    if ($currentPriority > $existingPriority) {
                        $speciesMap[$normalizedName] = $current;
                        Log::info("Replaced duplicate species with better status: {$normalizedName}", [
                            'old_status' => $existing['taxonomic_status'],
                            'new_status' => $current['taxonomic_status']
                        ]);
                    } else {
                        Log::info("Skipped duplicate species: {$normalizedName}", [
                            'kept_status' => $existing['taxonomic_status'],
                            'skipped_status' => $current['taxonomic_status']
                        ]);
                    }
                } else {
                    // New species, add it
                    $speciesMap[$normalizedName] = $speciesData;
                }
            }
        }
        
        // Convert map back to indexed array
        $formatted = array_values($speciesMap);
        
        // Collect GBIF keys for batch image fetching
        foreach ($formatted as $species) {
            if ($species['gbif_key']) {
                $speciesKeys[] = $species['gbif_key'];
            }
        }
        
        Log::info('Species deduplication results', [
            'original_count' => count($results),
            'species_level_count' => count($formatted),
            'deduplicated_count' => count($formatted)
        ]);
        
        // Second pass: fetch all images in batch
        if (!empty($speciesKeys)) {
            $images = $this->_fetchMultipleSpeciesImages($speciesKeys);
            
            // Update the formatted results with images
            for ($i = 0; $i < count($formatted); $i++) {
                $key = $formatted[$i]['gbif_key'];
                if ($key && isset($images[$key])) {
                    $formatted[$i]['reference_image'] = $images[$key];
                }
            }
        }
        
        return $formatted;
    }
    
    /**
     * Normalize species name for deduplication
     * Removes author citations and standardizes format
     */
    private function _normalizeSpeciesName(string $scientificName): string
    {
        // Remove everything after the species name (author citations, dates, etc.)
        // Match pattern: Genus species (everything else)
        if (preg_match('/^([A-Z][a-z]+ [a-z]+)/', $scientificName, $matches)) {
            return strtolower(trim($matches[1]));
        }
        
        // If no match, just return the original name normalized
        return strtolower(trim($scientificName));
    }

    /**
     * Resolve a synonym to its accepted name using GBIF API
     *
     * @param array $synonymResult The synonym result from GBIF search
     * @return array|null Accepted species data or null if not found
     */
    private function _resolveSynonymToAcceptedName(array $synonymResult): ?array
    {
        try {
            $gbifKey = $synonymResult['key'] ?? null;
            if (!$gbifKey) {
                Log::warning("No GBIF key found for synonym resolution");
                return null;
            }

            Log::info("Fetching detailed species data for synonym resolution", ['gbif_key' => $gbifKey]);

            // Get detailed species information from GBIF
            $speciesResponse = Http::timeout(5)->get("https://api.gbif.org/v1/species/{$gbifKey}");
            
            if (!$speciesResponse->ok()) {
                Log::warning("Failed to fetch species details for synonym resolution", [
                    'gbif_key' => $gbifKey,
                    'status' => $speciesResponse->status()
                ]);
                return null;
            }

            $detailedData = $speciesResponse->json();
            
            // Check if we have an accepted name key
            $acceptedKey = $detailedData['acceptedKey'] ?? $detailedData['accepted'] ?? null;
            
            if (!$acceptedKey || $acceptedKey === $gbifKey) {
                Log::info("No accepted name found or synonym is self-referencing", [
                    'gbif_key' => $gbifKey,
                    'accepted_key' => $acceptedKey
                ]);
                return null;
            }

            Log::info("Found accepted name key, fetching accepted species data", [
                'synonym_key' => $gbifKey,
                'accepted_key' => $acceptedKey
            ]);

            // Fetch the accepted species data
            $acceptedResponse = Http::timeout(5)->get("https://api.gbif.org/v1/species/{$acceptedKey}");
            
            if (!$acceptedResponse->ok()) {
                Log::warning("Failed to fetch accepted species data", [
                    'accepted_key' => $acceptedKey,
                    'status' => $acceptedResponse->status()
                ]);
                return null;
            }

            $acceptedData = $acceptedResponse->json();
            
            Log::info("Successfully resolved synonym to accepted name", [
                'synonym_name' => $synonymResult['scientificName'],
                'accepted_name' => $acceptedData['scientificName'],
                'accepted_key' => $acceptedKey
            ]);

            return $acceptedData;

        } catch (\Exception $e) {
            Log::error("Exception resolving synonym to accepted name", [
                'synonym_name' => $synonymResult['scientificName'] ?? 'unknown',
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Enrich taxonomic data for species with incomplete classification
     *
     * @param array $speciesArray Array of species data
     * @return array Enhanced species data with complete taxonomic information
     */
    private function _enrichTaxonomicData(array $speciesArray): array
    {
        foreach ($speciesArray as &$species) {
            if ($species['gbif_key']) {
                $enrichedData = $this->_getCompleteTaxonomicHierarchy($species['gbif_key']);
                if ($enrichedData) {
                    // Fill in any missing taxonomic levels
                    foreach (['domain', 'kingdom', 'phylum', 'class', 'order', 'family', 'genus'] as $level) {
                        if (($species[$level] === 'unknown' || empty($species[$level])) && 
                            !empty($enrichedData[$level])) {
                            $species[$level] = strtolower($enrichedData[$level]);
                            Log::debug("Enriched {$level} for {$species['scientific_name']}: {$enrichedData[$level]}");
                        }
                    }
                }
            }
        }
        
        return $speciesArray;
    }

    /**
     * Get complete taxonomic hierarchy from GBIF species endpoint
     *
     * @param int $gbifKey The GBIF species key
     * @return array|null Complete taxonomic data or null if not found
     */
    private function _getCompleteTaxonomicHierarchy(int $gbifKey): ?array
    {
        try {
            // Get the full species details from GBIF
            $speciesResponse = Http::timeout(3)->get("https://api.gbif.org/v1/species/{$gbifKey}");
            
            if (!$speciesResponse->ok()) {
                Log::warning("Failed to get complete taxonomy for GBIF key {$gbifKey}");
                return null;
            }
            
            $speciesData = $speciesResponse->json();
            
            // Start with what we have from the species data
            $taxonomicData = [
                'domain' => $speciesData['domain'] ?? 'eukaryota',
                'kingdom' => $speciesData['kingdom'] ?? null,
                'phylum' => $speciesData['phylum'] ?? null,
                'class' => $speciesData['class'] ?? null,
                'order' => $speciesData['order'] ?? null,
                'family' => $speciesData['family'] ?? null,
                'genus' => $speciesData['genus'] ?? null,
            ];
            
            // Manually search for each missing taxonomic level
            $this->_searchForTaxonomicLevel($taxonomicData, 'kingdom', $speciesData['scientificName']);
            $this->_searchForTaxonomicLevel($taxonomicData, 'phylum', $speciesData['scientificName']);
            $this->_searchForTaxonomicLevel($taxonomicData, 'class', $speciesData['scientificName']);
            $this->_searchForTaxonomicLevel($taxonomicData, 'order', $speciesData['scientificName']);
            $this->_searchForTaxonomicLevel($taxonomicData, 'family', $speciesData['scientificName']);
            $this->_searchForTaxonomicLevel($taxonomicData, 'genus', $speciesData['scientificName']);
            
            Log::debug("Complete taxonomy for GBIF key {$gbifKey}", $taxonomicData);
            
            return $taxonomicData;
            
        } catch (\Exception $e) {
            Log::error("Exception getting complete taxonomy for GBIF key {$gbifKey}: {$e->getMessage()}");
            return null;
        }
    }

    /**
     * Search for a specific taxonomic level by searching for the species
     *
     * @param array &$taxonomicData Reference to the taxonomic data array
     * @param string $targetLevel The level we want to fill (e.g., 'phylum')
     * @param string $scientificName The species scientific name to search for
     * @return void
     */
    private function _searchForTaxonomicLevel(array &$taxonomicData, string $targetLevel, string $scientificName): void
    {
        if (!empty($taxonomicData[$targetLevel]) || empty($scientificName)) {
            return; // Already have this level or no species name to search for
        }
        
        try {
            Log::debug("Searching for {$targetLevel} for species: {$scientificName}");
            
            // Search GBIF for this species with multiple approaches
            $searchQueries = [
                $scientificName, // Full scientific name
                explode(' ', $scientificName)[0] ?? $scientificName, // Just genus
            ];
            
            foreach ($searchQueries as $query) {
                $searchResponse = Http::timeout(3)->get('https://api.gbif.org/v1/species/search', [
                    'q' => $query,
                    'limit' => 10,
                    'rank' => 'SPECIES'
                ]);
                
                if (!$searchResponse->ok()) {
                    continue;
                }
                
                $results = $searchResponse->json()['results'] ?? [];
                
                foreach ($results as $result) {
                    // Look for results that have the target level and match our species
                    if (isset($result[$targetLevel]) && 
                        !empty($result[$targetLevel]) &&
                        isset($result['scientificName'])) {
                        
                        // Check if this result is relevant to our species
                        $resultName = strtolower($result['scientificName']);
                        $searchName = strtolower($scientificName);
                        
                        // Accept if it's the same species or same genus
                        if ($resultName === $searchName || 
                            strpos($resultName, explode(' ', $searchName)[0]) === 0) {
                            
                            $taxonomicData[$targetLevel] = $result[$targetLevel];
                            Log::debug("Found {$targetLevel} for {$scientificName}: {$result[$targetLevel]} (from {$result['scientificName']})");
                            return;
                        }
                    }
                }
            }
            
            // If we still don't have it, try a broader search for the genus
            if (empty($taxonomicData[$targetLevel])) {
                $genus = explode(' ', $scientificName)[0] ?? '';
                if ($genus) {
                    $this->_searchForTaxonomicLevelByGenus($taxonomicData, $targetLevel, $genus);
                }
            }
            
        } catch (\Exception $e) {
            Log::warning("Exception searching for {$targetLevel} of {$scientificName}: {$e->getMessage()}");
        }
    }

    /**
     * Search for a taxonomic level by searching for the genus
     *
     * @param array &$taxonomicData Reference to the taxonomic data array
     * @param string $targetLevel The level we want to fill
     * @param string $genus The genus name to search for
     * @return void
     */
    private function _searchForTaxonomicLevelByGenus(array &$taxonomicData, string $targetLevel, string $genus): void
    {
        try {
            Log::debug("Searching for {$targetLevel} by genus: {$genus}");
            
            $searchResponse = Http::timeout(3)->get('https://api.gbif.org/v1/species/search', [
                'q' => $genus,
                'limit' => 5,
                'rank' => 'GENUS'
            ]);
            
            if (!$searchResponse->ok()) {
                return;
            }
            
            $results = $searchResponse->json()['results'] ?? [];
            
            foreach ($results as $result) {
                if (isset($result[$targetLevel]) && 
                    !empty($result[$targetLevel]) &&
                    isset($result['scientificName']) &&
                    strtolower($result['scientificName']) === strtolower($genus)) {
                    
                    $taxonomicData[$targetLevel] = $result[$targetLevel];
                    Log::debug("Found {$targetLevel} for genus {$genus}: {$result[$targetLevel]}");
                    return;
                }
            }
            
        } catch (\Exception $e) {
            Log::warning("Exception searching for {$targetLevel} by genus {$genus}: {$e->getMessage()}");
        }
    }

        /**
     * Fetch images for multiple species concurrently
     *
     * @param  array $gbifKeys Array of GBIF species keys
     * @return array Associative array of key => image_data
     */
    private function _fetchMultipleSpeciesImages(array $gbifKeys): array
    {
        $images = [];
        
        Log::info('Starting image fetch for species', ['gbif_keys' => $gbifKeys]);
        
        // Process each species individually with error handling
        foreach ($gbifKeys as $key) {
            $imageUrl = null;
            
            Log::info("Searching for images for GBIF key: {$key}");
            
            // Try GBIF first
            $imageUrl = $this->_fetchImageFromGbif($key);
            if ($imageUrl) {
                Log::info("Found GBIF image for key {$key}: {$imageUrl}");
            } else {
                Log::info("No GBIF image found for key {$key}");
            }
            
            // If no GBIF image, try iNaturalist
            if (!$imageUrl) {
                Log::info("Trying iNaturalist for key {$key}");
                // Get species name from GBIF key first
                $speciesName = $this->_getSpeciesNameFromGbifKey($key);
                if ($speciesName) {
                    $imageUrl = $this->_fetchImageFromINaturalist($speciesName);
                    if ($imageUrl) {
                        Log::info("Found iNaturalist image for key {$key}: {$imageUrl}");
                    } else {
                        Log::info("No iNaturalist image found for key {$key}");
                    }
                }
            }
            
            // If still no image, try Wikipedia/Wikimedia
            if (!$imageUrl) {
                Log::info("Trying Wikipedia for key {$key}");
                $imageUrl = $this->_fetchImageFromWikipedia($key);
                if ($imageUrl) {
                    Log::info("Found Wikipedia image for key {$key}: {$imageUrl}");
                } else {
                    Log::info("No Wikipedia image found for key {$key}");
                }
            }
            
            // If still no image, try Flickr (as a last resort)
            if (!$imageUrl) {
                Log::info("Trying Flickr for key {$key}");
                $imageUrl = $this->_fetchImageFromFlickr($key);
                if ($imageUrl) {
                    Log::info("Found Flickr image for key {$key}: {$imageUrl}");
                } else {
                    Log::info("No Flickr image found for key {$key}");
                }
            }
            
            if ($imageUrl) {
                $images[$key] = $imageUrl;
                Log::info("Final image selected for key {$key}: {$imageUrl}");
            } else {
                Log::warning("No image found from any source for key {$key}");
            }
        }
        
        Log::info('Image fetch completed', ['total_images_found' => count($images)]);
        return $images;
    }

    /**
     * Fetch image from GBIF media API
     *
     * @param  int $gbifKey The GBIF species key
     *
     * @return string|null Image URL or null if not found
     */
    private function _fetchImageFromGbif(int $gbifKey): ?string
    {
        try {
            Log::debug("Calling GBIF media API for key: {$gbifKey}");
            $response = Http::timeout(3)->get(
                "https://api.gbif.org/v1/species/{$gbifKey}/media", 
                ['limit' => 3]
            );
            
            if ($response->ok()) {
                $data = $response->json();
                $mediaResults = $data['results'] ?? [];
                
                Log::debug("GBIF API response for key {$gbifKey}", [
                    'media_count' => count($mediaResults),
                    'results' => $mediaResults
                ]);
                
                foreach ($mediaResults as $media) {
                    if (isset($media['type']) 
                        && strtolower($media['type']) === 'stillimage'
                        && isset($media['identifier'])
                        && filter_var($media['identifier'], FILTER_VALIDATE_URL)
                    ) {
                        Log::debug("Found valid GBIF image for key {$gbifKey}: {$media['identifier']}");
                        return $media['identifier'];
                    }
                }
                Log::debug("No valid still images found in GBIF media for key {$gbifKey}");
            } else {
                Log::warning("GBIF API call failed for key {$gbifKey}", [
                    'status' => $response->status(),
                    'body' => $response->body()
                ]);
            }
        } catch (\Exception $e) {
            Log::error("Exception calling GBIF API for key {$gbifKey}: {$e->getMessage()}");
        }
        
        return null;
    }

    /**
     * Get species scientific name from GBIF key
     *
     * @param int $gbifKey The GBIF species key
     *
     * @return string|null The scientific name or null if not found
     */
    private function _getSpeciesNameFromGbifKey(int $gbifKey): ?string
    {
        try {
            $speciesResponse = Http::timeout(2)->get("https://api.gbif.org/v1/species/{$gbifKey}");
            
            if ($speciesResponse->ok()) {
                $speciesData = $speciesResponse->json();
                return $speciesData['scientificName'] ?? null;
            }
        } catch (\Exception $e) {
            Log::error("Exception getting species name from GBIF key {$gbifKey}: {$e->getMessage()}");
        }
        
        return null;
    }

    /**
     * Fetch image from iNaturalist API using taxon_photos
     *
     * @param string $speciesName The species name to search for
     *
     * @return string|null Image URL or null if not found
     */
    private function _fetchImageFromINaturalist(string $speciesName): ?string
    {
        try {
            Log::debug("Searching iNaturalist for: {$speciesName}");
            
            // Search iNaturalist for the species to get taxon ID
            $inatResponse = Http::timeout(3)->get(
                'https://api.inaturalist.org/v1/taxa',
                [
                    'q' => $speciesName,
                    'is_active' => 'true',
                    'locale' => 'en'
                ]
            );
            
            if ($inatResponse->ok()) {
                $data = $inatResponse->json();
                $results = $data['results'] ?? [];
                
                Log::debug("iNaturalist taxa search response for {$speciesName}", [
                    'result_count' => count($results)
                ]);
                
                if (!empty($results) && isset($results[0]['id'])) {
                    $taxonId = $results[0]['id'];
                    
                    // Now get taxon_photos for this taxon
                    $photosResponse = Http::timeout(3)->get(
                        "https://api.inaturalist.org/v1/taxa/{$taxonId}/taxon_photos",
                        ['per_page' => 5] // Get a few photos to choose from
                    );
                    
                    if ($photosResponse->ok()) {
                        $photosData = $photosResponse->json();
                        $taxonPhotos = $photosData['results'] ?? [];
                        
                        Log::debug("iNaturalist taxon_photos response for taxon {$taxonId}", [
                            'photo_count' => count($taxonPhotos)
                        ]);
                        
                        if (!empty($taxonPhotos) && isset($taxonPhotos[0]['photo']['large_url'])) {
                            $imageUrl = $taxonPhotos[0]['photo']['large_url'];
                            Log::debug("Found iNaturalist taxon photo for {$speciesName}: {$imageUrl}");
                            return $imageUrl;
                        }
                    } else {
                        Log::warning("iNaturalist taxon_photos API call failed for taxon {$taxonId}", [
                            'status' => $photosResponse->status()
                        ]);
                    }
                }
                Log::debug("No taxon photos found in iNaturalist for {$speciesName}");
            } else {
                Log::warning("iNaturalist taxa API call failed for {$speciesName}", [
                    'status' => $inatResponse->status()
                ]);
            }
        } catch (\Exception $e) {
            Log::error("Exception in iNaturalist search for {$speciesName}: {$e->getMessage()}");
        }
        
        return null;
    }

    /**
     * Fetch image from Wikipedia API
     *
     * @param int $gbifKey The GBIF species key
     *
     * @return string|null Image URL or null if not found
     */
    private function _fetchImageFromWikipedia(int $gbifKey): ?string
    {
        try {
            // First get the scientific name from GBIF
            $speciesResponse = Http::timeout(2)->get("https://api.gbif.org/v1/species/{$gbifKey}");
            
            if (!$speciesResponse->ok()) {
                return null;
            }
            
            $speciesData = $speciesResponse->json();
            $scientificName = $speciesData['scientificName'] ?? null;
            
            if (!$scientificName) {
                return null;
            }
            
            // Search Wikipedia for the species page
            $wikiResponse = Http::timeout(3)->get('https://en.wikipedia.org/api/rest_v1/page/summary/' . urlencode($scientificName));
            
            if ($wikiResponse->ok()) {
                $data = $wikiResponse->json();
                
                // Check if there's a thumbnail image
                if (isset($data['thumbnail']['source'])) {
                    return $data['thumbnail']['source'];
                }
                
                // Check for original image
                if (isset($data['originalimage']['source'])) {
                    return $data['originalimage']['source'];
                }
            }
        } catch (\Exception $e) {
            // Fail silently
        }
        
        return null;
    }

    /**
     * Fetch image from Flickr using scientific name (as last resort)
     *
     * @param int $gbifKey The GBIF species key
     *
     * @return string|null Image URL or null if not found
     */
    private function _fetchImageFromFlickr(int $gbifKey): ?string
    {
        try {
            // First get the scientific name from GBIF
            $speciesResponse = Http::timeout(2)->get("https://api.gbif.org/v1/species/{$gbifKey}");
            
            if (!$speciesResponse->ok()) {
                return null;
            }
            
            $speciesData = $speciesResponse->json();
            $scientificName = $speciesData['scientificName'] ?? null;
            
            if (!$scientificName) {
                return null;
            }
            
            // Use Flickr's public feed (no API key required)
            $flickrResponse = Http::timeout(3)->get('https://www.flickr.com/services/feeds/photos_public.gne', [
                'tags' => str_replace(' ', ',', $scientificName),
                'format' => 'json',
                'nojsoncallback' => 1
            ]);
            
            if ($flickrResponse->ok()) {
                $data = $flickrResponse->json();
                $items = $data['items'] ?? [];
                
                if (!empty($items)) {
                    // Get the first image
                    $firstItem = $items[0];
                    if (isset($firstItem['media']['m'])) {
                        // Convert to larger size (replace _m with _b for large size)
                        return str_replace('_m.jpg', '_b.jpg', $firstItem['media']['m']);
                    }
                }
            }
        } catch (\Exception $e) {
            // Fail silently
        }
        
        return null;
    }

    /**
     * Enrich taxonomic data using information from search results
     *
     * @param array $targetResult The target result to enrich
     * @param array $allResults All search results to find additional data
     * @return array Enriched taxonomic data
     */
    private function _enrichTaxonomicDataFromSearchResults(array $targetResult, array $allResults): array
    {
        $enriched = $targetResult;
        $targetCanonical = $targetResult['canonicalName'] ?? $targetResult['scientificName'] ?? '';
        
        Log::info("Enriching taxonomic data for: {$targetCanonical}");
        
        // Log the initial state
        Log::info("Initial target result for {$targetCanonical}", [
            'kingdom' => $targetResult['kingdom'] ?? 'N/A',
            'phylum' => $targetResult['phylum'] ?? 'N/A',
            'class' => $targetResult['class'] ?? 'N/A',
            'order' => $targetResult['order'] ?? 'N/A',
            'family' => $targetResult['family'] ?? 'N/A'
        ]);
        
        // Look for other results with the same species that have more complete data
        foreach ($allResults as $result) {
            $resultCanonical = $result['canonicalName'] ?? $result['scientificName'] ?? '';
            
            // Only consider results for the same species
            if (strtolower($resultCanonical) !== strtolower($targetCanonical)) {
                continue;
            }
            
            // Fill in missing taxonomic levels with data from this result
            $taxonomicLevels = ['kingdom', 'phylum', 'class', 'order', 'family', 'genus'];
            
            foreach ($taxonomicLevels as $level) {
                if ((!isset($enriched[$level]) || empty($enriched[$level])) && 
                    isset($result[$level]) && !empty($result[$level])) {
                    $enriched[$level] = $result[$level];
                    Log::info("Enriched {$level} for {$targetCanonical}: {$result[$level]}");
                }
            }
            
            // Also collect vernacular names for common name extraction
            if (isset($result['vernacularNames']) && is_array($result['vernacularNames'])) {
                if (!isset($enriched['vernacularNames'])) {
                    $enriched['vernacularNames'] = [];
                }
                $enriched['vernacularNames'] = array_merge(
                    $enriched['vernacularNames'], 
                    $result['vernacularNames']
                );
            }
        }
        
        // Use known bird taxonomy if still missing critical information
        if (isset($enriched['class']) && strtolower($enriched['class']) === 'aves') {
            if (!isset($enriched['phylum']) || empty($enriched['phylum']) || $enriched['phylum'] === 'unknown') {
                $enriched['phylum'] = 'Chordata';
                Log::info("Applied bird taxonomy - phylum: Chordata for {$targetCanonical}");
            }
            if (!isset($enriched['kingdom']) || empty($enriched['kingdom']) || $enriched['kingdom'] === 'unknown') {
                $enriched['kingdom'] = 'Animalia';
                Log::info("Applied bird taxonomy - kingdom: Animalia for {$targetCanonical}");
            }
        }
        
        Log::info("Taxonomic enrichment completed for {$targetCanonical}", [
            'kingdom' => $enriched['kingdom'] ?? 'unknown',
            'phylum' => $enriched['phylum'] ?? 'unknown',
            'class' => $enriched['class'] ?? 'unknown',
            'order' => $enriched['order'] ?? 'unknown',
            'family' => $enriched['family'] ?? 'unknown'
        ]);
        
        return $enriched;
    }

    /**
     * Extract preferred common name from GBIF search results
     *
     * @param array $enrichedData The enriched data from multiple sources
     * @param array $originalResult The original GBIF search result
     * @return string|null The preferred common name or null if not found
     */
    private function _extractPreferredCommonName(array $enrichedData, array $originalResult): ?string
    {
        // Check for vernacular names in the enriched data first
        $vernacularSources = [$enrichedData, $originalResult];
        
        foreach ($vernacularSources as $source) {
            if (isset($source['vernacularNames']) && is_array($source['vernacularNames'])) {
                Log::info("Found vernacular names in source", [
                    'count' => count($source['vernacularNames']),
                    'first_few' => array_slice($source['vernacularNames'], 0, 3)
                ]);
                
                // Look for English names first
                foreach ($source['vernacularNames'] as $name) {
                    if (isset($name['language']) && 
                        $name['language'] === 'eng' && 
                        isset($name['vernacularName'])) {
                        Log::info("Found English common name: {$name['vernacularName']}");
                        return $name['vernacularName'];
                    }
                }
                
                // Fallback to first available common name if no English found
                foreach ($source['vernacularNames'] as $name) {
                    if (isset($name['vernacularName'])) {
                        $language = $name['language'] ?? 'unknown language';
                        Log::info("Using fallback common name: {$name['vernacularName']} ({$language})");
                        return $name['vernacularName'];
                    }
                }
            }
        }
        
        Log::info("No common name found in GBIF data");
        return null;
    }

    /**
     * Fetch complete taxonomic data from GBIF using species key
     *
     * @param int|null $gbifKey The GBIF species key
     * @param array $fallbackData Fallback data from search results
     * @return array Complete species data with full taxonomy
     */
    private function _fetchCompleteSpeciesData(?int $gbifKey, array $fallbackData = []): array
    {
        if (!$gbifKey) {
            Log::warning('No GBIF key provided for complete data fetch');
            return $fallbackData;
        }

        Log::info("Fetching complete taxonomic data for GBIF key: {$gbifKey}");

        try {
            $response = Http::timeout(5)->get("https://api.gbif.org/v1/species/{$gbifKey}");
            
            if (!$response->ok()) {
                Log::warning("Failed to fetch complete data for GBIF key {$gbifKey}: HTTP {$response->status()}");
                return $fallbackData;
            }

            $data = $response->json();
            
            // Log what we received
            Log::info("Complete GBIF data received for key {$gbifKey}", [
                'scientific_name' => $data['scientificName'] ?? 'N/A',
                'kingdom' => $data['kingdom'] ?? 'N/A',
                'phylum' => $data['phylum'] ?? 'N/A',
                'class' => $data['class'] ?? 'N/A',
                'order' => $data['order'] ?? 'N/A',
                'family' => $data['family'] ?? 'N/A',
                'genus' => $data['genus'] ?? 'N/A'
            ]);

            // Merge complete data with fallback, preferring complete data
            return array_merge($fallbackData, $data);
            
        } catch (\Exception $e) {
            Log::error("Exception fetching complete GBIF data for key {$gbifKey}: " . $e->getMessage());
            return $fallbackData;
        }
    }
}