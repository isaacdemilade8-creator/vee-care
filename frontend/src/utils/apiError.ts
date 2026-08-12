export function getApiErrorMessage(error: unknown, fallback = 'Something went wrong. Please try again.') {
  if (typeof error === 'string') {
    return error;
  }

  if (error instanceof Error) {
    return error.message || fallback;
  }

  if (typeof error === 'object' && error !== null) {
    const maybeResponse = error as {
      response?: { data?: { message?: string } | string };
      message?: string;
    };

    if (typeof maybeResponse.response?.data === 'string') {
      return maybeResponse.response.data;
    }

    if (typeof maybeResponse.response?.data?.message === 'string') {
      return maybeResponse.response.data.message;
    }

    if (typeof maybeResponse.message === 'string' && maybeResponse.message.trim()) {
      return maybeResponse.message;
    }
  }

  return fallback;
}

/**
 * Extracts Laravel-style validation errors from an axios error as a flat
 * field -> first-message map, for inline form display.
 */
export function getValidationErrors(error: unknown): Record<string, string> {
  if (typeof error !== 'object' || error === null) {
    return {};
  }

  const errors = (error as {
    response?: { data?: { errors?: Record<string, string[]> } };
  }).response?.data?.errors;

  if (!errors) {
    return {};
  }

  const flattened: Record<string, string> = {};
  for (const [field, messages] of Object.entries(errors)) {
    flattened[field] = messages[0] ?? '';
  }

  return flattened;
}
