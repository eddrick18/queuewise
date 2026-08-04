import axios from "axios";

type LaravelErrorResponse = {
  message?: string;
  errors?: Record<string, string[]>;
};

export function getErrorMessage(
  error: unknown,
): string {
  if (!axios.isAxiosError<LaravelErrorResponse>(error)) {
    return "Something went wrong. Please try again.";
  }

  const responseData = error.response?.data;

  if (responseData?.errors) {
    const firstErrorGroup = Object.values(
      responseData.errors,
    )[0];

    if (firstErrorGroup?.[0]) {
      return firstErrorGroup[0];
    }
  }

  if (responseData?.message) {
    return responseData.message;
  }

  if (!error.response) {
    return "Cannot connect to Laravel. Make sure the backend is running.";
  }

  return "The request could not be completed.";
}