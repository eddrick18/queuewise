import { useCallback, useEffect, useRef, useState, type FormEvent } from "react";
import { Link, useNavigate } from "react-router-dom";
import { useAuth } from "../context/AuthContext";
import { getErrorMessage } from "../lib/getErrorMessage";
import { createStaffAccount, getStaffAccounts, updateStaffAccount, type StaffAccount } from "../services/adminStaffService";
import "./AdminServicesPage.css";

export default function AdminStaffPage() {
  const { user, logoutUser } = useAuth();
  const navigate = useNavigate();
  const [accounts, setAccounts] = useState<StaffAccount[]>([]);
  const [loaded, setLoaded] = useState(false);
  const [busy, setBusy] = useState<string | null>(null);
  const operationInProgress = useRef(false);
  const [error, setError] = useState("");
  const [success, setSuccess] = useState("");
  const [editingId, setEditingId] = useState<number | null>(null);
  const [name, setName] = useState("");
  const [email, setEmail] = useState("");
  const [password, setPassword] = useState("");
  const [confirmation, setConfirmation] = useState("");
  const [active, setActive] = useState(true);
  const nameInput = useRef<HTMLInputElement>(null);

  const loadAccounts = useCallback(async () => {
    if (operationInProgress.current) return;
    operationInProgress.current = true;
    setBusy("load");
    setError("");
    try {
      setAccounts(await getStaffAccounts());
      setLoaded(true);
    } catch (requestError) {
      setError(getErrorMessage(requestError));
    } finally {
      operationInProgress.current = false;
      setBusy(null);
    }
  }, []);

  useEffect(() => { void loadAccounts(); }, [loadAccounts]);

  function resetForm() {
    setEditingId(null);
    setName("");
    setEmail("");
    setPassword("");
    setConfirmation("");
    setActive(true);
  }

  function editAccount(account: StaffAccount) {
    setEditingId(account.id);
    setName(account.name);
    setEmail(account.email);
    setActive(account.is_active);
    setPassword("");
    setConfirmation("");
    setError("");
    setSuccess("");
    nameInput.current?.focus();
  }

  function applyAccount(account: StaffAccount) {
    setAccounts((current) => [...current.filter((item) => item.id !== account.id), account]
      .sort((a, b) => a.name.localeCompare(b.name) || a.id - b.id));
  }

  async function saveAccount(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (operationInProgress.current) return;
    setError("");
    setSuccess("");
    if (!name.trim()) {
      setError("Enter a staff member’s name.");
      nameInput.current?.focus();
      return;
    }
    if (editingId === null && password !== confirmation) {
      setError("The passwords do not match.");
      return;
    }
    operationInProgress.current = true;
    setBusy("save");
    try {
      const details = { name: name.trim(), email: email.trim().toLowerCase(), is_active: active };
      const account = editingId === null
        ? await createStaffAccount({ ...details, password, password_confirmation: confirmation })
        : await updateStaffAccount(editingId, details);
      applyAccount(account);
      setSuccess(`${account.name} ${editingId === null ? "created" : "updated"} successfully.`);
      resetForm();
    } catch (requestError) {
      setError(getErrorMessage(requestError));
    } finally {
      operationInProgress.current = false;
      setBusy(null);
    }
  }

  async function toggleAccount(account: StaffAccount) {
    if (operationInProgress.current) return;
    operationInProgress.current = true;
    setBusy(`toggle-${account.id}`);
    setError("");
    setSuccess("");
    try {
      const updated = await updateStaffAccount(account.id, { is_active: !account.is_active });
      applyAccount(updated);
      if (editingId === updated.id) setActive(updated.is_active);
      setSuccess(`${updated.name} ${updated.is_active ? "activated" : "deactivated"}.`);
    } catch (requestError) {
      setError(getErrorMessage(requestError));
    } finally {
      operationInProgress.current = false;
      setBusy(null);
    }
  }

  async function logout() {
    if (operationInProgress.current) return;
    operationInProgress.current = true;
    setBusy("logout");
    setError("");
    try {
      await logoutUser();
      navigate("/login");
    } catch (requestError) {
      setError(getErrorMessage(requestError));
    } finally {
      operationInProgress.current = false;
      setBusy(null);
    }
  }

  if (!user) return null;
  const disabled = busy !== null;
  const activeCount = accounts.filter((account) => account.is_active).length;

  return (
    <main className="staff-page admin-page">
      <header className="dashboard-header">
        <div><p className="eyebrow">QUEUEWISE</p><strong>Administrator Portal</strong></div>
        <div className="staff-header-actions">
          <span className="admin-identity">{user.name}</span>
          <Link className="secondary-button" to="/admin">Manage services</Link>
          <Link className="secondary-button" to="/staff">Queue operations</Link>
          <button className="secondary-button" type="button" onClick={() => void logout()} disabled={disabled}>{busy === "logout" ? "Logging out..." : "Log out"}</button>
        </div>
      </header>
      <section className="staff-content">
        <div className="welcome-block">
          <p className="eyebrow">STAFF ACCOUNTS</p>
          <h1>Manage your team.</h1>
          <p className="supporting-text">Create staff accounts and control who can manage your queues.</p>
        </div>
        {error && <div className="form-error staff-message" role="alert">{error}</div>}
        {success && <div className="form-success staff-message" role="status">{success}</div>}
        <div className="section-heading admin-list-heading">
          <div><h2>Staff members</h2>{loaded && <p className="supporting-text">{activeCount} active · {accounts.length - activeCount} inactive</p>}</div>
          <button className="secondary-button" type="button" disabled={disabled} onClick={() => void loadAccounts()}>{busy === "load" ? "Loading..." : "Refresh staff"}</button>
        </div>
        {!loaded ? <p className="dashboard-loading">{busy === "load" ? "Loading staff accounts..." : "Unable to load staff accounts. Select Refresh staff to try again."}</p> : (
          <div className="admin-service-layout">
            <div className="admin-service-list">
              {accounts.length === 0 && <div className="staff-empty-list"><strong>No staff accounts yet</strong><p>Create your first staff account using the form.</p></div>}
              {accounts.map((account) => (
                <article className="admin-service-card" key={account.id}>
                  <div className="admin-card-title"><h3>{account.name}</h3><span className={`admin-badge ${account.is_active ? "is-active" : "is-inactive"}`}>{account.is_active ? "Active" : "Inactive"}</span></div>
                  <p className="admin-description">{account.email}</p>
                  <div className="admin-card-actions">
                    <button className="secondary-button" type="button" disabled={disabled} onClick={() => editAccount(account)} aria-label={`Edit ${account.name}`}>Edit details</button>
                    <button className="secondary-button" type="button" disabled={disabled} onClick={() => void toggleAccount(account)} aria-label={`${account.is_active ? "Deactivate" : "Activate"} ${account.name}`}>
                      {busy === `toggle-${account.id}` ? "Saving..." : account.is_active ? "Deactivate" : "Activate"}
                    </button>
                  </div>
                </article>
              ))}
            </div>
            <form className="admin-service-form" onSubmit={saveAccount}>
              <h2>{editingId === null ? "Add a staff member" : "Edit staff member"}</h2>
              <fieldset disabled={disabled}>
                <label className="form-field"><span>Full name</span><input ref={nameInput} value={name} onChange={(event) => setName(event.target.value)} required maxLength={255} autoComplete="off" /></label>
                <label className="form-field"><span>Email address</span><input type="email" value={email} onChange={(event) => setEmail(event.target.value)} required maxLength={255} autoComplete="off" /></label>
                {editingId === null && <>
                  <label className="form-field"><span>Password</span><input type="password" value={password} onChange={(event) => setPassword(event.target.value)} required minLength={8} maxLength={72} autoComplete="new-password" /></label>
                  <label className="form-field"><span>Confirm password</span><input type="password" value={confirmation} onChange={(event) => setConfirmation(event.target.value)} required minLength={8} maxLength={72} autoComplete="new-password" /></label>
                  <p className="admin-form-hint">Use at least 8 characters. Share the login details privately with the staff member.</p>
                </>}
                <label className="admin-checkbox"><input type="checkbox" checked={active} onChange={(event) => setActive(event.target.checked)} /><span>Active — allow this staff member to access QueueWise</span></label>
                <p className="admin-form-hint">Deactivation blocks new logins and further actions in existing sessions. The account and queue history are retained.</p>
                <div className="admin-card-actions">
                  <button className="primary-button" type="submit">{busy === "save" ? "Saving..." : editingId === null ? "Create staff account" : "Save changes"}</button>
                  {editingId !== null && <button className="secondary-button" type="button" onClick={resetForm}>Cancel edit</button>}
                </div>
              </fieldset>
            </form>
          </div>
        )}
      </section>
    </main>
  );
}
