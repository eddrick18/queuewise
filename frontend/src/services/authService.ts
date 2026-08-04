import api from "../lib/api";

export type UserRole = "customer" | "staff" | "admin";

export type User = {
  id: number;
  name: string;
  email: string;
  role: UserRole;
};

export type RegisterData = {
  name: string;
  email: string;
  password: string;
  password_confirmation: string;
};

export type LoginData = {
  email: string;
  password: string;
};

type AuthResponse = {
  message: string;
  user: User;
};

export async function getCsrfCookie(): Promise<void> {
  await api.get("/sanctum/csrf-cookie");
}

export async function registerAccount(
  data: RegisterData,
): Promise<User> {
  await getCsrfCookie();

  const response = await api.post<AuthResponse>(
    "/register",
    data,
  );

  return response.data.user;
}

export async function loginAccount(
  data: LoginData,
): Promise<User> {
  await getCsrfCookie();

  const response = await api.post<AuthResponse>(
    "/login",
    data,
  );

  return response.data.user;
}

export async function getCurrentUser(): Promise<User> {
  const response = await api.get<{ user: User }>(
    "/api/user",
  );

  return response.data.user;
}

export async function logoutAccount(): Promise<void> {
  await api.post("/logout");
}