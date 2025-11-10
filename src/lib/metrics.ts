export interface CounterMetric {
  inc: (value?: number) => void;
}

class NoopCounter implements CounterMetric {
  inc(_value?: number) {}
}

export const metrics = {
  registrationsCreated: new NoopCounter(),
  emailsSent: new NoopCounter()
};
