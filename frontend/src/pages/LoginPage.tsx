import {
  useState,
  type FormEvent,
} from "react";

import {
  Link,
  Navigate,
  useNavigate,
} from "react-router-dom";

import { useAuth } from "../context/AuthContext";
import { getErrorMessage } from "../lib/getErrorMessage";
import { roleHome } from "../lib/roleHome";

export default function LoginPage() {
  const navigate = useNavigate();

  const {
    user,
    loginUser,
  } = useAuth();

  const [email, setEmail] = useState("");
  const [password, setPassword] = useState("");

  const [error, setError] = useState("");
  const [submitting, setSubmitting] = useState(false);

  if (user) {
    return <Navigate to={roleHome(user.role)} replace />;
  }

  async function handleSubmit(
    event: FormEvent<HTMLFormElement>,
  ) {
    event.preventDefault();

    setError("");
    setSubmitting(true);

    try {
      const loggedInUser = await loginUser({
        email,
        password,
      });

      navigate(roleHome(loggedInUser.role));
    } catch (requestError) {
      setError(getErrorMessage(requestError));
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <main className="auth-page">
      <section className="auth-panel">
        <div className="brand-block">
          <p className="eyebrow">QUEUEWISE</p>

          <h1>Your place in line, available online.</h1>

          <p className="supporting-text">
            Log in to view services, join a queue, and monitor
            your current position.
          </p>
        </div>

        <form
          className="auth-form"
          onSubmit={handleSubmit}
        >
          <div className="form-heading">
            <p className="eyebrow">WELCOME BACK</p>
            <h2>Log in</h2>
          </div>

          {error && (
            <div className="form-error" role="alert">
              {error}
            </div>
          )}

          <label className="form-field">
            <span>Email address</span>

            <input
              type="email"
              value={email}
              onChange={(event) =>
                setEmail(event.target.value)
              }
              placeholder="name@example.com"
              autoComplete="email"
              required
            />
          </label>

          <label className="form-field">
            <span>Password</span>

            <input
              type="password"
              value={password}
              onChange={(event) =>
                setPassword(event.target.value)
              }
              placeholder="Enter your password"
              autoComplete="current-password"
              required
            />
          </label>

          <button
            className="primary-button"
            type="submit"
            disabled={submitting}
          >
            {submitting ? "Logging in..." : "Log in"}
          </button>

          <p className="form-footer">
            Need an account?{" "}
            <Link to="/register">Register</Link>
          </p>
        </form>
      </section>
    </main>
  );
}
