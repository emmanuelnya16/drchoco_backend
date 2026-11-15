/**
 * Exporte les données de démonstration du front (fixtures des mocks) en JSON,
 * pour remplir la base de l'API avec exactement les mêmes articles, catégories, looks,
 * réglages, villes, agences et pages.
 *
 * À lancer depuis le dossier du FRONT (pour que l'alias « @/ » soit résolu) :
 *   npx tsx --tsconfig tsconfig.app.json "<chemin du back>/tools/export-front-fixtures.ts" "<chemin du back>/data/seed"
 */
import { writeFileSync } from 'node:fs';
import { join } from 'node:path';
import { mockCategories } from '@/mocks/fixtures/categories';
import { mockLooks } from '@/mocks/fixtures/looks';
import { createSeedProducts } from '@/mocks/fixtures/products';
import { mockAgencies, mockDeliveryCities, mockPublicSettings } from '@/mocks/fixtures/settings';
import { mockContentPages } from '@/mocks/fixtures/pages';

const outDir = process.argv[2];
if (!outDir) {
  console.error('Usage : export-front-fixtures.ts <dossier de sortie>');
  process.exit(1);
}

const write = (name: string, data: unknown) => {
  writeFileSync(join(outDir, name), JSON.stringify(data, null, 2) + '\n', 'utf8');
  console.log(`${name} écrit`);
};

// Les vendeurs des mocks (adm-03, adm-04) n'existent pas dans l'API : seule la ville est gardée.
const products = createSeedProducts().map(({ sellerId: _s, sellerName: _n, badges: _b, ...p }) => p);

write('categories.json', mockCategories);
write('products.json', products);
write('looks.json', mockLooks);
write('settings.json', mockPublicSettings);
write('delivery-cities.json', mockDeliveryCities);
write('agencies.json', mockAgencies);
write('pages.json', Object.values(mockContentPages));
