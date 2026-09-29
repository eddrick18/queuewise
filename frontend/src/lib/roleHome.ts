import type { UserRole } from "../services/authService";

export function roleHome(role: UserRole): string {
  return { customer: "/dashboard", staff: "/staff", admin: "/admin" }[role];
}
