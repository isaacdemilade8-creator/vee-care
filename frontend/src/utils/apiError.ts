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
