export type PaymentStatus = "INITIATED" | "PENDING" | "PAID" | "FAILED" | "REFUNDED";

export interface PaymentIntent {
  id: string;
  amountCents: number;
  currency: string;
  status: PaymentStatus;
}

export interface PaymentProvider {
  createIntent(amountCents: number, currency: string): Promise<PaymentIntent>;
  markPaid(id: string): Promise<void>;
}

class MockPaymentProvider implements PaymentProvider {
  async createIntent(amountCents: number, currency: string) {
    return {
      id: `mock_${Date.now()}`,
      amountCents,
      currency,
      status: "INITIATED"
    };
  }
  async markPaid() {
    return;
  }
}

export const paymentProvider = new MockPaymentProvider();
