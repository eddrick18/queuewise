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
import AdminServicesPage from "./pages/AdminServicesPage";
import AdminStaffPage from "./pages/AdminStaffPage";
import AppointmentsPage from "./pages/AppointmentsPage";
import AdminQueueHistoryPage from "./pages/AdminQueueHistoryPage";
import { roleHome } from "./lib/roleHome";

import "./App.css";
import "./workspace.css";

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

  const destination = roleHome(user.role);

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
      <Route path="/appointments" element={<RoleProtectedRoute allowedRoles={["customer"]}><AppointmentsPage /></RoleProtectedRoute>} />
      <Route path="/staff/appointments" element={<RoleProtectedRoute allowedRoles={["staff", "admin"]}><AppointmentsPage /></RoleProtectedRoute>} />
      <Route path="/admin/history" element={<RoleProtectedRoute allowedRoles={["admin"]}><AdminQueueHistoryPage /></RoleProtectedRoute>} />
      <Route
        path="/admin/staff"
        element={
          <RoleProtectedRoute allowedRoles={["admin"]}>
            <AdminStaffPage />
          </RoleProtectedRoute>
        }
      />
      <Route
        path="/admin"
        element={
          <RoleProtectedRoute allowedRoles={["admin"]}>
            <AdminServicesPage />
          </RoleProtectedRoute>
        }
      />
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
