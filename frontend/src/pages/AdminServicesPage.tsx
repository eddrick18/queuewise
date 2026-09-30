import { useCallback, useEffect, useRef, useState, type FormEvent } from "react";
import { Link, useNavigate } from "react-router-dom";
import { useAuth } from "../context/AuthContext";
import { getErrorMessage } from "../lib/getErrorMessage";
import { createService, getAdminServices, updateService } from "../services/adminService";
import type { Service } from "../services/queueService";
import "./AdminServicesPage.css";

export default function AdminServicesPage() {
  const { user, logoutUser } = useAuth();
  const navigate = useNavigate();
  const [services, setServices] = useState<Service[]>([]);
  const [loaded, setLoaded] = useState(false);
  const [busy, setBusy] = useState<string | null>(null);
  const operationInProgress = useRef(false);
  const [error, setError] = useState("");
  const [success, setSuccess] = useState("");
  const [editingId, setEditingId] = useState<number | null>(null);
  const [name, setName] = useState("");
  const [description, setDescription] = useState("");
  const [minutes, setMinutes] = useState("15");
  const [active, setActive] = useState(true);
  const nameInput = useRef<HTMLInputElement>(null);

  const loadServices = useCallback(async () => {
    if (operationInProgress.current) return;
    operationInProgress.current = true;
    setBusy("load");
    setError("");
    try {
      setServices(await getAdminServices());
      setLoaded(true);
    } catch (requestError) {
      setError(getErrorMessage(requestError));
    } finally {
      operationInProgress.current = false;
      setBusy(null);
    }
  }, []);

  useEffect(() => { void loadServices(); }, [loadServices]);

  function resetForm() {
    setEditingId(null);
    setName("");
    setDescription("");
    setMinutes("15");
    setActive(true);
  }

  function editService(service: Service) {
    setEditingId(service.id);
    setName(service.name);
    setDescription(service.description ?? "");
    setMinutes(String(service.average_service_minutes));
    setActive(service.is_active);
    setError("");
    setSuccess("");
    nameInput.current?.focus();
  }

  function applyService(service: Service) {
    setServices((current) => [...current.filter((item) => item.id !== service.id), service]
      .sort((a, b) => a.name.localeCompare(b.name)));
  }

  async function saveService(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (operationInProgress.current) return;
    if (!name.trim()) {
      setError("Enter a service name.");
      nameInput.current?.focus();
      return;
    }
    operationInProgress.current = true;
    setBusy("save");
    setError("");
    setSuccess("");
    try {
      const data = {
        name: name.trim(),
        description: description.trim() || null,
        average_service_minutes: Number(minutes),
        is_active: active,
      };
      const service = editingId === null
        ? await createService(data)
        : await updateService(editingId, data);
      applyService(service);
      setSuccess(`${service.name} ${editingId === null ? "created" : "updated"} successfully.`);
      resetForm();
    } catch (requestError) {
      setError(getErrorMessage(requestError));
    } finally {
      operationInProgress.current = false;
      setBusy(null);
    }
  }

  async function toggleService(service: Service) {
    if (operationInProgress.current) return;
    operationInProgress.current = true;
    setBusy(`toggle-${service.id}`);
    setError("");
    setSuccess("");
    try {
      const updated = await updateService(service.id, { is_active: !service.is_active });
      applyService(updated);
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
  const activeCount = services.filter((service) => service.is_active).length;

  return (
    <main className="staff-page admin-page">
      <header className="dashboard-header">
        <div><p className="eyebrow">QUEUEWISE</p><strong>Administrator Portal</strong></div>
        <div className="staff-header-actions">
          <span className="admin-identity">{user.name}</span>
          <Link className="secondary-button" to="/admin/staff">Staff accounts</Link>
          <Link className="secondary-button" to="/admin/history">Queue history</Link>
          <Link className="secondary-button" to="/staff">Queue operations</Link>
          <button className="secondary-button" type="button" onClick={() => void logout()} disabled={disabled}>
            {busy === "logout" ? "Logging out..." : "Log out"}
          </button>
        </div>
      </header>
      <section className="staff-content">
        <div className="welcome-block">
          <p className="eyebrow">SERVICE MANAGEMENT</p>
          <h1>Manage your services.</h1>
          <p className="supporting-text">Set up the services customers can join and keep their estimated service times up to date.</p>
        </div>
        {error && <div className="form-error staff-message" role="alert">{error}</div>}
        {success && <div className="form-success staff-message" role="status">{success}</div>}
        <div className="section-heading admin-list-heading">
          <div>
            <h2>Services</h2>
            {loaded && <p className="supporting-text">{activeCount} active · {services.length - activeCount} inactive</p>}
          </div>
          <button className="secondary-button" type="button" disabled={disabled} onClick={() => void loadServices()}>
            {busy === "load" ? "Loading..." : "Refresh services"}
          </button>
        </div>
        {!loaded ? <p className="dashboard-loading">{busy === "load" ? "Loading services..." : "Unable to load services. Select Refresh services to try again."}</p> : (
          <div className="admin-service-layout">
            <div className="admin-service-list">
              {services.length === 0 && <div className="staff-empty-list"><strong>No services yet</strong><p>Create your first service using the form.</p></div>}
              {services.map((service) => (
                <article className="admin-service-card" key={service.id}>
                  <div className="admin-card-title">
                    <h3>{service.name}</h3>
                    <span className={`admin-badge ${service.is_active ? "is-active" : "is-inactive"}`}>{service.is_active ? "Active" : "Inactive"}</span>
                  </div>
                  <p className="admin-description">{service.description || "No description provided."}</p>
                  <p className="admin-duration">{service.average_service_minutes} minutes per customer</p>
                  <div className="admin-card-actions">
                    <button className="secondary-button" type="button" disabled={disabled} onClick={() => editService(service)} aria-label={`Edit ${service.name}`}>Edit service</button>
                    <button className="secondary-button" type="button" disabled={disabled} onClick={() => void toggleService(service)} aria-label={`${service.is_active ? "Deactivate" : "Activate"} ${service.name}`}>
                      {busy === `toggle-${service.id}` ? "Saving..." : service.is_active ? "Deactivate" : "Activate"}
                    </button>
                  </div>
                </article>
              ))}
            </div>
            <form className="admin-service-form" onSubmit={saveService}>
              <h2>{editingId === null ? "Add a service" : "Edit service"}</h2>
              <fieldset disabled={disabled}>
                <label className="form-field"><span>Service name</span><input ref={nameInput} value={name} onChange={(event) => setName(event.target.value)} required maxLength={255} /></label>
                <label className="form-field"><span>Description <small>(optional)</small></span><textarea value={description} onChange={(event) => setDescription(event.target.value)} rows={4} maxLength={2000} /></label>
                <label className="form-field"><span>Average service time (minutes)</span><input type="number" value={minutes} onChange={(event) => setMinutes(event.target.value)} required min={1} max={1440} step={1} /></label>
                <label className="admin-checkbox"><input type="checkbox" checked={active} onChange={(event) => setActive(event.target.checked)} /><span>Active — customers can join this service</span></label>
                <p className="admin-form-hint">Finish or cancel today’s active queues before deactivating a service. Queue history is retained.</p>
                <div className="admin-card-actions">
                  <button className="primary-button" type="submit">{busy === "save" ? "Saving..." : editingId === null ? "Create service" : "Save changes"}</button>
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
