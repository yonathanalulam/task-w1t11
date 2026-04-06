export type ApiSuccess<T> = {
  data: T;
  meta?: { requestId?: string | null };
};

export type ApiError = {
  error: {
    code: string;
    message: string;
    details?: Array<Record<string, unknown>>;
  };
  meta?: { requestId?: string | null };
};

export async function apiGet<T>(path: string): Promise<T> {
  const response = await fetch(path, {
    credentials: 'include',
  });

  const payload = (await response.json()) as ApiSuccess<T> | ApiError;
  if (!response.ok) {
    throw payload;
  }

  return (payload as ApiSuccess<T>).data;
}

export async function apiPost<T>(
  path: string,
  body: Record<string, unknown>,
  csrfToken?: string,
): Promise<T> {
  const response = await fetch(path, {
    method: 'POST',
    credentials: 'include',
    headers: {
      'Content-Type': 'application/json',
      ...(csrfToken ? { 'X-CSRF-Token': csrfToken } : {}),
    },
    body: JSON.stringify(body),
  });

  if (response.status === 204) {
    return {} as T;
  }

  const payload = (await response.json()) as ApiSuccess<T> | ApiError;
  if (!response.ok) {
    throw payload;
  }

  return (payload as ApiSuccess<T>).data;
}

export async function apiPostForm<T>(path: string, body: FormData, csrfToken?: string): Promise<T> {
  const response = await fetch(path, {
    method: 'POST',
    credentials: 'include',
    headers: {
      ...(csrfToken ? { 'X-CSRF-Token': csrfToken } : {}),
    },
    body,
  });

  const payload = (await response.json()) as ApiSuccess<T> | ApiError;
  if (!response.ok) {
    throw payload;
  }

  return (payload as ApiSuccess<T>).data;
}

export async function apiPut<T>(
  path: string,
  body: Record<string, unknown>,
  csrfToken?: string,
): Promise<T> {
  const response = await fetch(path, {
    method: 'PUT',
    credentials: 'include',
    headers: {
      'Content-Type': 'application/json',
      ...(csrfToken ? { 'X-CSRF-Token': csrfToken } : {}),
    },
    body: JSON.stringify(body),
  });

  const payload = (await response.json()) as ApiSuccess<T> | ApiError;
  if (!response.ok) {
    throw payload;
  }

  return (payload as ApiSuccess<T>).data;
}
