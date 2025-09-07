# SpeciesDex

A comprehensive species identification application that acts as a real-world Pokédex for all living things. Built with Laravel (backend/API) and React (frontend), it uses Google Cloud Vision API and GBIF (Global Biodiversity Information Facility) to identify and provide detailed taxonomic information about species from photographs.

## Features

- **Photo-based Species Identification**: Upload photos to identify species using Google Cloud Vision API
- **Comprehensive Taxonomic Data**: Complete classification hierarchy from domain to species
- **Enhanced Search**: Smart search with synonym resolution and canonical name lookup
- **Manual Search**: Search species by common or scientific name
- **Preferred Common Names**: Displays localized common names where available
- **Reference Images**: Fetches images from multiple sources (GBIF, iNaturalist, Wikipedia)
- **Synonym Resolution**: Automatically resolves taxonomic synonyms to accepted names
- **Progressive Web App**: Responsive React frontend optimized for mobile use

## Tech Stack

### Backend
- **Laravel 11** - PHP framework
- **MySQL/MariaDB** - Database
- **GBIF API** - Species data and taxonomy
- **Google Cloud Vision API** - Image recognition
- **DDEV** - Local development environment

### Frontend
- **React 18** - UI framework
- **Progressive Web App** - Mobile-optimized experience

## Project Structure
```
SpeciesDex/
├── backend/               # Laravel API backend
│   ├── app/Http/Controllers/
│   │   └── SpeciesIdentifyController.php  # Main species logic
│   ├── routes/api.php     # API routes
│   └── public/            # Frontend build output
├── frontend/              # React frontend (PWA)
│   ├── src/
│   │   └── App.js         # Main React component
│   └── public/
├── .ddev/                 # DDEV configuration
└── test-species-api.html  # Test interface
```

## Getting Started

### Prerequisites
- [DDEV](https://ddev.readthedocs.io/en/stable/)
- Docker
- Google Cloud Vision API key

### Setup

1. **Clone the repository**
   ```bash
   git clone https://github.com/your-username/SpeciesDex.git
   cd SpeciesDex
   ```

2. **Start DDEV**
   ```bash
   ddev start
   ```

3. **Backend Setup**
   ```bash
   # Install dependencies
   ddev composer install -d backend
   
   # Set up environment
   cp backend/.env.example backend/.env
   ddev exec php backend/artisan key:generate
   
   # Run migrations
   ddev exec php backend/artisan migrate
   ```

4. **Configure API Keys**
   - Get a Google Cloud Vision API key from [Google Cloud Console](https://console.cloud.google.com/apis/credentials)
   - Add it to `backend/.env`:
     ```
     GOOGLE_CLOUD_VISION_API_KEY=your_actual_api_key_here
     ```

5. **Frontend Setup**
   ```bash
   cd frontend
   npm install
   npm run build
   cd ..
   cp -r frontend/build/* backend/public/
   ```

6. **Access the Application**
   - Main app: [https://speciesdex.ddev.site](https://speciesdex.ddev.site)
   - Test interface: [https://speciesdex.ddev.site/test-species-api.html](https://speciesdex.ddev.site/test-species-api.html)

## API Endpoints

- `POST /api/identify` - Identify species from uploaded image
- `POST /api/species-details` - Get detailed species information by name

## External APIs Used

- **GBIF API** (https://api.gbif.org)
  - Species search and taxonomy
  - Vernacular names
  - Species media
- **Google Cloud Vision API**
  - Image content detection and labeling

## Development

### Running in Development Mode

```bash
# Backend (Laravel)
ddev exec php backend/artisan serve

# Frontend (React)
cd frontend && npm start
```

### Testing

Use the included test interface at `/test-species-api.html` for manual testing of the API endpoints.

## Contributing

1. Fork the repository
2. Create a feature branch
3. Make your changes
4. Add tests if applicable
5. Submit a pull request

## Environment Variables

See `backend/.env.example` for all available configuration options. Key variables:

- `GOOGLE_CLOUD_VISION_API_KEY` - Required for image identification
- `DB_*` - Database configuration (auto-configured by DDEV)
- `APP_URL` - Application URL

## License

This project is open source. Please check the license file for details.

## Acknowledgments

- [GBIF](https://www.gbif.org/) - Global Biodiversity Information Facility
- [Google Cloud Vision API](https://cloud.google.com/vision) - Image recognition
- [iNaturalist](https://www.inaturalist.org/) - Additional species images
