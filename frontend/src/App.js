
import React, { useState, useRef } from 'react';
import Tree from 'react-d3-tree';
import './App.css';
// Helper to fetch numDescendants for a usageKey
const fetchChildCount = async (usageKey) => {
  try {
    const resp = await fetch(`https://api.gbif.org/v1/species/${usageKey}`);
    const data = await resp.json();
    return data.numDescendants || 0;
  } catch {
    return 0;
  }
};
// Helper to fetch vernacular (common) names for a species usageKey
const fetchVernacularName = async (usageKey) => {
  try {
    const resp = await fetch(`https://api.gbif.org/v1/species/${usageKey}/vernacularNames`);
    const data = await resp.json();
    if (data.results && data.results.length > 0) {
      // Prefer English, fallback to first
      const en = data.results.find(v => v.language === 'eng');
      return en ? en.vernacularName : data.results[0].vernacularName;
    }
  } catch {}
  return null;
};

function App() {
  const [image, setImage] = useState(null);
  const [uploading, setUploading] = useState(false);
  const [error, setError] = useState("");
  // Store all added species classifications
  const [speciesList, setSpeciesList] = useState([]);
  // Map of usageKey to common name
  const [commonNames, setCommonNames] = useState({});
  // Map of usageKey to total child count
  const [childCounts, setChildCounts] = useState({});
  // Track which usageKeys are being fetched to avoid duplicate requests
  const fetchingChildCounts = useRef({});
  // Species selection modal state
  const [showSpeciesModal, setShowSpeciesModal] = useState(false);
  const [speciesOptions, setSpeciesOptions] = useState([]);
  // Manual species search state
  const [manualSearchInput, setManualSearchInput] = useState("");
  const [manualSearching, setManualSearching] = useState(false);

  // Search GBIF API for species name using /v1/species/search

  // Send image to backend for Google Vision analysis
  const identifySpeciesFromImage = async (file) => {
    const formData = new FormData();
    formData.append('image', file);
    const resp = await fetch('/api/identify-species', {
      method: 'POST',
      body: formData
    });
    if (!resp.ok) throw new Error('Failed to identify species');
    const data = await resp.json();
    return data; // Return full response instead of just label
  };

  // Handle species detection when user selects from modal
  const handleDetectedSpecies = async (speciesName) => {
    try {
      // Call our new API endpoint to get detailed GBIF information
      const resp = await fetch('/api/species-details', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
        },
        body: JSON.stringify({ species_name: speciesName }),
      });
      
      if (!resp.ok) {
        throw new Error('Failed to get species details');
      }
      
      const data = await resp.json();
      
      if (data.success && data.species_results && data.species_results.length > 0) {
        // If multiple results, show the selection modal with GBIF data
        if (data.species_results.length > 1) {
          setSpeciesOptions(data.species_results);
          setShowSpeciesModal(true);
        } else {
          // Single result, add directly
          await handleSpeciesSelection(data.species_results[0]);
        }
      } else {
        setError(`No GBIF information found for "${speciesName}".`);
      }
    } catch (err) {
      setError(`Error getting details for "${speciesName}".`);
    }
  };

  // Handle species selection directly from detection modal
  const handleSpeciesModalSelection = async (speciesName) => {
    try {
      // Close the modal first
      setShowSpeciesModal(false);
      
      // Use our enhanced backend API instead of direct GBIF calls
      const resp = await fetch('/api/species-details', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
        },
        body: JSON.stringify({ species_name: speciesName }),
      });
      
      if (!resp.ok) {
        throw new Error('Failed to get species details from backend');
      }
      
      const data = await resp.json();
      
      if (data.success && data.species_results && data.species_results.length > 0) {
        // Use the first (best) result from the enhanced backend search
        const bestSpecies = data.species_results[0];
        
        const completeSpecies = {
          ...bestSpecies,
          usageKey: bestSpecies.gbif_key,
          scientificName: bestSpecies.scientific_name
        };
        
        // Add directly to the tree
        setSpeciesList(prev => {
          if (prev.some(s => s.usageKey === completeSpecies.usageKey)) return prev;
          return [...prev, completeSpecies];
        });
        
        // Use preferred common name from API response, or fetch as fallback
        if (bestSpecies.preferred_common_name) {
          setCommonNames(prev => ({ ...prev, [completeSpecies.usageKey]: bestSpecies.preferred_common_name }));
        } else if (!commonNames[completeSpecies.usageKey]) {
          fetchVernacularName(completeSpecies.usageKey).then(name => {
            if (name) setCommonNames(prev => ({ ...prev, [completeSpecies.usageKey]: name }));
          });
        }
        
        // Fetch and store child count
        if (!childCounts[completeSpecies.usageKey] && !fetchingChildCounts.current[completeSpecies.usageKey]) {
          fetchingChildCounts.current[completeSpecies.usageKey] = true;
          fetchChildCount(completeSpecies.usageKey).then(count => {
            setChildCounts(prev => ({ ...prev, [completeSpecies.usageKey]: count }));
            fetchingChildCounts.current[completeSpecies.usageKey] = false;
          });
        }
      } else {
        setError(`Enhanced search found no results for "${speciesName}".`);
      }
    } catch (err) {
      console.error('Error adding selected species:', err);
      setError('Failed to add species to tree. Please try again.');
    }
  };

  // Handle species selection from modal
  const handleSpeciesSelection = async (selectedSpecies) => {
    setShowSpeciesModal(false);
    
    if (!selectedSpecies) {
      setError("No species selected.");
      return;
    }

    // Use our enhanced backend API for better classification data
    try {
      const resp = await fetch('/api/species-details', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
        },
        body: JSON.stringify({ species_name: selectedSpecies.scientific_name }),
      });
      
      if (!resp.ok) {
        throw new Error('Failed to get species details from backend');
      }
      
      const data = await resp.json();
      
      if (data.success && data.species_results && data.species_results.length > 0) {
        // Use the first (best) result from the enhanced backend search
        const bestSpecies = data.species_results[0];
        
        const completeSpecies = {
          ...bestSpecies,
          usageKey: bestSpecies.gbif_key,
          scientificName: bestSpecies.scientific_name
        };
        
        setSpeciesList(prev => {
          if (prev.some(s => s.usageKey === completeSpecies.usageKey)) return prev;
          return [...prev, completeSpecies];
        });
        
        // Use preferred common name from API response, or fetch as fallback
        if (bestSpecies.preferred_common_name) {
          setCommonNames(prev => ({ ...prev, [completeSpecies.usageKey]: bestSpecies.preferred_common_name }));
        } else if (!commonNames[completeSpecies.usageKey]) {
          fetchVernacularName(completeSpecies.usageKey).then(name => {
            if (name) setCommonNames(prev => ({ ...prev, [completeSpecies.usageKey]: name }));
          });
        }
        
        // Fetch and store child count
        if (!childCounts[completeSpecies.usageKey] && !fetchingChildCounts.current[completeSpecies.usageKey]) {
          fetchingChildCounts.current[completeSpecies.usageKey] = true;
          fetchChildCount(completeSpecies.usageKey).then(count => {
            setChildCounts(prev => ({ ...prev, [completeSpecies.usageKey]: count }));
            fetchingChildCounts.current[completeSpecies.usageKey] = false;
          });
        }
      } else {
        setError("Enhanced search found no results for selected species.");
      }
    } catch (err) {
      setError("Error adding selected species with enhanced backend search.");
    }
  };

  // Handle photo upload and identification
  const handlePhoto = async (e) => {
    const file = e.target.files[0];
    if (!file) return;
    setImage(URL.createObjectURL(file));
    setUploading(true);
    setError("");
    try {
      const identificationResult = await identifySpeciesFromImage(file);
      if (!identificationResult || !identificationResult.success) {
        setError("Could not identify species from photo.");
        setUploading(false);
        return;
      }

      const { species_options } = identificationResult;

      // Check if we have multiple species options
      if (species_options && species_options.length > 1) {
        // Show modal for species selection
        setSpeciesOptions(species_options);
        setShowSpeciesModal(true);
      } else if (species_options && species_options.length === 1) {
        // Directly process the single detection
        await handleDetectedSpecies(species_options[0].name);
      } else {
        setError("No species detected in image.");
      }
    } catch (err) {
      setError("Error identifying species.");
    }
    setUploading(false);
  };

  // Handle manual species search
  const handleManualSearch = async (e) => {
    e.preventDefault();
    if (!manualSearchInput.trim()) return;
    
    setManualSearching(true);
    setError("");
    
    try {
      await handleDetectedSpecies(manualSearchInput.trim());
      setManualSearchInput(""); // Clear the input after successful search
    } catch (err) {
      setError(`Error searching for "${manualSearchInput}".`);
    }
    
    setManualSearching(false);
  };

  // No longer needed: fetchClassification, as /match returns classification info


  // Build a merged tree in react-d3-tree format, tracking usageKeys for all nodes
  // Function to capitalize the first letter of each word
  const capitalize = (str) => {
    if (!str || str === 'unknown') return str;
    return str.split(' ').map(word => 
      word.charAt(0).toUpperCase() + word.slice(1).toLowerCase()
    ).join(' ');
  };

  const buildMergedTree = (speciesArr) => {
    // Each node: { name, attributes, children: [] }
    const root = { name: 'Life', children: [] };
    
    // Helper function to get all taxonomic data from existing tree nodes
    const getExistingTaxonomyData = () => {
      const taxonomyMap = new Map();
      
      const collectTaxonomy = (node, currentPath = []) => {
        if (node.attributes?.label && node.name) {
          const level = node.attributes.label.toLowerCase();
          const path = [...currentPath, { level, name: node.name, usageKey: node.attributes.usageKey }];
          
          // Store the full path for this node
          taxonomyMap.set(`${level}:${node.name.toLowerCase()}`, path);
          
          if (node.children) {
            for (const child of node.children) {
              collectTaxonomy(child, path);
            }
          }
        } else if (node.children) {
          for (const child of node.children) {
            collectTaxonomy(child, currentPath);
          }
        }
      };
      
      collectTaxonomy(root);
      return taxonomyMap;
    };

    // Process each species
    for (const s of speciesArr) {
      // Get current taxonomy data from existing tree
      const existingTaxonomy = getExistingTaxonomyData();
      
      // Try to get usageKeys for each layer from s and higherClassificationMap
      const getLayer = (field, fallback) => {
        let value = s[field] || (s.higherClassificationMap && Object.values(s.higherClassificationMap).find(v => v === fallback)) || null;
        let key = s[field + 'Key'] || (s.higherClassificationMap && Object.keys(s.higherClassificationMap).find(k => s.higherClassificationMap[k] === value));
        return { value: value ? capitalize(value) : value, usageKey: key ? parseInt(key) : undefined };
      };

      // Define the taxonomic hierarchy
      const rawLayers = [
        { label: 'Domain', ...getLayer('domain', 'Eukaryota') },
        { label: 'Kingdom', ...getLayer('kingdom', 'Animalia') },
        { label: 'Phylum', ...getLayer('phylum', 'Chordata') },
        { label: 'Class', ...getLayer('class', s.class) },
        { label: 'Order', ...getLayer('order', s.order) },
        { label: 'Family', ...getLayer('family', s.family) },
        { label: 'Genus', ...getLayer('genus', s.genus) },
        { label: 'Species', value: s.scientificName, usageKey: s.usageKey }
      ];

      // Fill in missing levels by looking at existing tree data
      const layers = rawLayers.map((layer, index) => {
        if (layer.value && layer.value !== 'Unknown') {
          return layer;
        }

        // Try to find this level from existing tree data
        // Look for any species that shares taxonomic levels with this one
        for (const [key, path] of existingTaxonomy) {
          const [level, name] = key.split(':');
          
          // Check if this existing path shares any known levels with our current species
          let hasSharedTaxonomy = false;
          
          for (let i = index + 1; i < rawLayers.length; i++) {
            const futureLayer = rawLayers[i];
            if (futureLayer.value && futureLayer.value !== 'Unknown') {
              const futureLevelName = futureLayer.label.toLowerCase();
              const futureValueLower = futureLayer.value.toLowerCase();
              
              // Check if this path contains the same future taxonomic level
              const matchingLevel = path.find(p => 
                p.level === futureLevelName && 
                p.name.toLowerCase() === futureValueLower
              );
              
              if (matchingLevel) {
                hasSharedTaxonomy = true;
                break;
              }
            }
          }
          
          // If we found shared taxonomy, use the missing level from this path
          if (hasSharedTaxonomy && level === layer.label.toLowerCase()) {
            return {
              label: layer.label,
              value: capitalize(name),
              usageKey: path.find(p => p.level === level)?.usageKey
            };
          }
        }

        // If we couldn't find it in existing data, return the original layer
        return layer;
      });

      // Build the tree path
      let node = root;
      for (let i = 0; i < layers.length; i++) {
        const layer = layers[i];
        if (!layer.value || layer.value === 'Unknown') continue;
        
        let child = (node.children || []).find(c => c.name === layer.value);
        if (!child) {
          child = {
            name: layer.value,
            attributes: { label: layer.label, usageKey: layer.usageKey },
            children: []
          };
          node.children = node.children || [];
          node.children.push(child);
        }
        node = child;
      }
    }
    
    return root;
  };

  // Render the merged tree using react-d3-tree
  const renderMergedTree = () => {
    if (speciesList.length === 0) return null;
    const treeData = buildMergedTree(speciesList);
    // Custom node rendering to show label and common name
    const renderCustomNode = ({ nodeDatum }) => {
      const usageKey = nodeDatum.attributes && nodeDatum.attributes.usageKey;
      const commonName = usageKey && commonNames[usageKey];
      const sciName = nodeDatum.name;
      // Number of children found in the tree
      const foundChildren = nodeDatum.children ? nodeDatum.children.length : 0;
      // Total possible children from GBIF API
      const totalChildren = usageKey ? childCounts[usageKey] : undefined;
      // If we haven't fetched this node's child count yet, do so (for all nodes with usageKey)
      if (usageKey && totalChildren === undefined && !fetchingChildCounts.current[usageKey]) {
        fetchingChildCounts.current[usageKey] = true;
        fetchChildCount(usageKey).then(count => {
          setChildCounts(prev => ({ ...prev, [usageKey]: count }));
          fetchingChildCounts.current[usageKey] = false;
        });
      }
      return (
        <g>
          <rect x={-60} y={-40} width={120} height={80} rx={40} fill="#e0f7fa" stroke="#0097a7" strokeWidth={3} />
          {/* Main label: common name or scientific name if no common */}
          <text fill="#00796b" stroke="none" x={0} y={0} textAnchor="middle" fontWeight="bold" fontSize="16">
            {commonName || sciName}
          </text>
          {/* Smaller: scientific name if common name exists */}
          {commonName && (
            <text fill="#0097a7" stroke="none" x={0} y={20} textAnchor="middle" fontSize="12" fontStyle="italic">
              {sciName}
            </text>
          )}
          {/* Node type label (Kingdom, Family, etc.) */}
          <text fill="#0097a7" stroke="none" x={0} y={34} textAnchor="middle" fontSize="11">
            {nodeDatum.attributes && nodeDatum.attributes.label}
          </text>
          {/* Child completion count from API */}
          {totalChildren !== undefined && (
            <text fill="#ff9800" stroke="none" x={0} y={-28} textAnchor="middle" fontSize="11">
              {foundChildren}/{totalChildren} found
            </text>
          )}
        </g>
      );
    };
    return (
      <div style={{ width: '100%', height: '600px', background: '#fafafa', border: '1px solid #eee', borderRadius: 8, margin: '32px 0', overflow: 'auto' }}>
        <Tree
          data={treeData}
          orientation="horizontal"
          translate={{ x: 100, y: 300 }}
          renderCustomNodeElement={renderCustomNode}
          pathFunc="elbow"
          zoomable={true}
          collapsible={false}
        />
      </div>
    );
  };

  // Species Selection Modal Component
  const SpeciesSelectionModal = () => {
    if (!showSpeciesModal) return null;

    return (
      <div style={{
        position: 'fixed',
        top: 0,
        left: 0,
        right: 0,
        bottom: 0,
        backgroundColor: 'rgba(0, 0, 0, 0.5)',
        display: 'flex',
        alignItems: 'center',
        justifyContent: 'center',
        zIndex: 1000
      }}>
        <div style={{
          backgroundColor: 'white',
          borderRadius: '12px',
          padding: '24px',
          maxWidth: '600px',
          width: '90%',
          maxHeight: '80vh',
          overflow: 'auto',
          boxShadow: '0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04)'
        }}>
          <h2 style={{ marginTop: 0, marginBottom: '16px', color: '#1f2937' }}>
            Select the Detected Species
          </h2>
          <p style={{ color: '#6b7280', marginBottom: '24px' }}>
            We detected multiple possible species in your image. Please select the one that best matches what you photographed:
          </p>
          
          <div style={{ marginBottom: '24px' }}>
            {speciesOptions.map((speciesOption, index) => (
              <div
                key={index}
                onClick={() => handleSpeciesModalSelection(speciesOption.name)}
                style={{
                  border: '1px solid #e5e7eb',
                  borderRadius: '8px',
                  padding: '16px',
                  marginBottom: '12px',
                  cursor: 'pointer',
                  transition: 'all 0.2s',
                  backgroundColor: '#f9fafb',
                  display: 'flex',
                  gap: '16px',
                  alignItems: 'center'
                }}
                onMouseEnter={(e) => {
                  e.currentTarget.style.backgroundColor = '#f3f4f6';
                  e.currentTarget.style.borderColor = '#0097a7';
                }}
                onMouseLeave={(e) => {
                  e.currentTarget.style.backgroundColor = '#f9fafb';
                  e.currentTarget.style.borderColor = '#e5e7eb';
                }}
              >
                {/* Species Image */}
                <div style={{ flexShrink: 0 }}>
                  {speciesOption.image ? (
                    <img 
                      src={speciesOption.image} 
                      alt={speciesOption.name}
                      style={{
                        width: '80px',
                        height: '80px',
                        objectFit: 'cover',
                        borderRadius: '6px',
                        border: '1px solid #d1d5db'
                      }}
                      onError={(e) => {
                        e.target.style.display = 'none';
                        e.target.nextSibling.style.display = 'flex';
                      }}
                    />
                  ) : null}
                  <div style={{
                    width: '80px',
                    height: '80px',
                    backgroundColor: '#e5e7eb',
                    borderRadius: '6px',
                    display: speciesOption.image ? 'none' : 'flex',
                    alignItems: 'center',
                    justifyContent: 'center',
                    color: '#9ca3af',
                    fontSize: '12px',
                    textAlign: 'center'
                  }}>
                    No Image
                  </div>
                </div>
                
                {/* Species Info */}
                <div style={{ flex: 1 }}>
                  <div style={{ fontWeight: 'bold', fontSize: '18px', color: '#1f2937', marginBottom: '4px' }}>
                    {speciesOption.name}
                  </div>
                  <div style={{ fontSize: '14px', color: '#6b7280' }}>
                    <strong>Confidence:</strong> {(speciesOption.score * 100).toFixed(1)}%
                  </div>
                  <div style={{ fontSize: '12px', color: '#9ca3af' }}>
                    <strong>Source:</strong> {speciesOption.source === 'species_detection' ? 'Species-specific detection' : 'General detection'}
                  </div>
                </div>
                
                {/* Select Button */}
                <div style={{
                  backgroundColor: '#0097a7',
                  color: 'white',
                  padding: '8px 16px',
                  borderRadius: '4px',
                  fontSize: '12px',
                  fontWeight: 'bold',
                  flexShrink: 0
                }}>
                  Select
                </div>
              </div>
            ))}
          </div>

          <div style={{ display: 'flex', gap: '12px', justifyContent: 'flex-end' }}>
            <button
              onClick={() => setShowSpeciesModal(false)}
              style={{
                padding: '12px 24px',
                border: '1px solid #d1d5db',
                borderRadius: '6px',
                backgroundColor: 'white',
                color: '#374151',
                cursor: 'pointer',
                fontSize: '14px'
              }}
            >
              Cancel
            </button>
            <button
              onClick={() => handleSpeciesSelection(null)}
              style={{
                padding: '12px 24px',
                border: 'none',
                borderRadius: '6px',
                backgroundColor: '#0097a7',
                color: 'white',
                cursor: 'pointer',
                fontSize: '14px'
              }}
            >
              None of these are correct
            </button>
          </div>
        </div>
      </div>
    );
  };

  return (
    <div className="App" style={{display: 'flex', flexDirection: 'column', minHeight: '100vh'}}>
      <SpeciesSelectionModal />
      <div style={{flex: 1, padding: 24}}>
        <h1>SpeciesDex</h1>
        {error && <div style={{color: 'red'}}>{error}</div>}
  {renderMergedTree()}
  {/* Only show the tree, no list of buttons */}
      </div>
      <div style={{position: 'sticky', bottom: 0, background: '#fff', padding: 16, borderTop: '1px solid #eee'}}>
        {/* Manual Species Search */}
        <form onSubmit={handleManualSearch} style={{display: 'flex', alignItems: 'center', gap: 16, marginBottom: 16}}>
          <label style={{fontSize: 18, fontWeight: 'bold', minWidth: 'max-content'}}>Search species:</label>
          <input
            type="text"
            value={manualSearchInput}
            onChange={(e) => setManualSearchInput(e.target.value)}
            placeholder="Enter species name (e.g., 'American Robin' or 'Turdus migratorius')"
            disabled={manualSearching}
            style={{
              flex: 1,
              fontSize: 16,
              padding: '8px 12px',
              border: '1px solid #ccc',
              borderRadius: '4px'
            }}
          />
          <button
            type="submit"
            disabled={manualSearching || !manualSearchInput.trim()}
            style={{
              fontSize: 16,
              padding: '8px 16px',
              backgroundColor: '#0097a7',
              color: 'white',
              border: 'none',
              borderRadius: '4px',
              cursor: manualSearching || !manualSearchInput.trim() ? 'not-allowed' : 'pointer',
              opacity: manualSearching || !manualSearchInput.trim() ? 0.6 : 1
            }}
          >
            {manualSearching ? 'Searching...' : 'Search'}
          </button>
        </form>
        
        {/* Photo Upload */}
        <div style={{display: 'flex', alignItems: 'center', gap: 16}}>
          <label style={{fontSize: 18, fontWeight: 'bold'}}>Take or upload a photo:</label>
          <input
          type="file"
          accept="image/*"
          capture="environment"
          onChange={handlePhoto}
          disabled={uploading}
          style={{fontSize: 16}}
        />
        {uploading && <span style={{marginLeft: 16}}>Identifying species...</span>}
        {image && <img src={image} alt="Uploaded" style={{height: 48, marginLeft: 16, borderRadius: 8}} />}
        </div>
      </div>
    </div>
  );
}

export default App;
