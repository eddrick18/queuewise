import type { ReactNode } from "react";

import {
  Navigate,
} from "react-router-dom";

import { useAuth } from "../context/AuthContext";

import type {
  UserRole,
} from "../services/authService";

type RoleProtectedRouteProps = {
  children: ReactNode;
  allowedRoles: UserRole[];
};

export default function RoleProtectedRoute({
  children,
  allowedRoles,
}: RoleProtectedRouteProps) {
  const {
    user,
    loading,
  } = useAuth();

  if (loading) {
    return (
      <main className="center-page">
        <p className="loading-message">
          Checking your account...
        </p>
      </main>
    );
  }

  if (!user) {
    return (
      <Navigate
        to="/login"
        replace
      />
    );
  }

  if (!allowedRoles.includes(user.role)) {
    const correctPage =
      user.role === "customer"
        ? "/dashboard"
        : "/staff";

    return (
      <Navigate
        to={correctPage}
        replace
      />
    );
  }

  return children;
}