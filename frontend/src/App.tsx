import {
  Navigate,
  Route,
  Routes,
} from "react-router-dom";

import RoleProtectedRoute from "./components/RoleProtectedRoute";

import { useAuth } from "./context/AuthContext";

import DashboardPage from "./pages/DashboardPage";
import LoginPage from "./pages/LoginPage";
import RegisterPage from "./pages/RegisterPage";
import StaffDashboardPage from "./pages/StaffDashboardPage";

import "./App.css";

function HomeRoute() {
  const {
    user,
    loading,
  } = useAuth();

  if (loading) {
    return (
      <main className="center-page">
        <p className="loading-message">
          Loading QueueWise...
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

  const destination =
    user.role === "customer"
      ? "/dashboard"
      : "/staff";

  return (
    <Navigate
      to={destination}
      replace
    />
  );
}

function App() {
  return (
    <Routes>
      <Route
        path="/"
        element={<HomeRoute />}
      />

      <Route
        path="/register"
        element={<RegisterPage />}
      />

      <Route
        path="/login"
        element={<LoginPage />}
      />

      <Route
        path="/dashboard"
        element={
          <RoleProtectedRoute
            allowedRoles={[
              "customer",
            ]}
          >
            <DashboardPage />
          </RoleProtectedRoute>
        }
      />

      <Route
        path="/staff"
        element={
          <RoleProtectedRoute
            allowedRoles={[
              "staff",
              "admin",
            ]}
          >
            <StaffDashboardPage />
          </RoleProtectedRoute>
        }
      />

      <Route
        path="*"
        element={
          <Navigate
            to="/"
            replace
          />
        }
      />
    </Routes>
  );
}

export default App;