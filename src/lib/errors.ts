export class UnauthorizedError extends Error {
  status = 401;
  constructor(message = "Unauthorized") {
    super(message);
  }
}

export class ForbiddenError extends Error {
  status = 403;
  constructor(message = "Forbidden") {
    super(message);
  }
}

export class RateLimitError extends Error {
  status = 429;
  constructor(message = "Rate limit exceeded") {
    super(message);
  }
}
