export interface CatalogItem {
  id: number;
  title: string;
  sku: string;
  basePriceMinor: number;
  currency: string;
  override: {
    priceMinor: number | null;
    status: boolean;
  } | null;
}

export interface CustomProduct {
  id: number;
  title: string;
  sku: string;
  priceMinor: number;
  currency: string;
}

export interface PaymentSettingsPayload {
  apiLoginId: string;
  transactionKey: string;
  publicClientKey: string;
}
