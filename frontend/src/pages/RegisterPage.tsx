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

export default function RegisterPage() {
  const navigate = useNavigate();

  const {
    user,
    registerUser,
  } = useAuth();

  const [name, setName] = useState("");
  const [email, setEmail] = useState("");
  const [password, setPassword] = useState("");
  const [
    passwordConfirmation,
    setPasswordConfirmation,
  ] = useState("");

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
      await registerUser({
        name,
        email,
        password,
        password_confirmation: passwordConfirmation,
      });

      navigate("/dashboard");
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

          <h1>Join the queue without standing in line.</h1>

          <p className="supporting-text">
            Create your customer account to select a service,
            receive a queue number, and monitor your status.
          </p>
        </div>

        <form
          className="auth-form"
          onSubmit={handleSubmit}
        >
          <div className="form-heading">
            <p className="eyebrow">CREATE ACCOUNT</p>
            <h2>Register</h2>
          </div>

          {error && (
            <div className="form-error" role="alert">
              {error}
            </div>
          )}

          <label className="form-field">
            <span>Full name</span>

            <input
              type="text"
              value={name}
              onChange={(event) =>
                setName(event.target.value)
              }
              placeholder="Eddrick Miano"
              autoComplete="name"
              required
            />
          </label>

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
              placeholder="At least 8 characters"
              autoComplete="new-password"
              minLength={8}
              required
            />
          </label>

          <label className="form-field">
            <span>Confirm password</span>

            <input
              type="password"
              value={passwordConfirmation}
              onChange={(event) =>
                setPasswordConfirmation(
                  event.target.value,
                )
              }
              placeholder="Enter the password again"
              autoComplete="new-password"
              minLength={8}
              required
            />
          </label>

          <button
            className="primary-button"
            type="submit"
            disabled={submitting}
          >
            {submitting
              ? "Creating account..."
              : "Create account"}
          </button>

          <p className="form-footer">
            Already registered?{" "}
            <Link to="/login">Log in</Link>
          </p>
        </form>
      </section>
    </main>
  );
}
