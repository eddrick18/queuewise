import {
  useCallback,
  useEffect,
  useRef,
  useState,
} from "react";

import { useNavigate } from "react-router-dom";

import { useAuth } from "../context/AuthContext";
import { getErrorMessage } from "../lib/getErrorMessage";

import {
  getCurrentQueue,
  getServices,
  joinQueue,
  type QueueEntry,
  type QueueStatus,
  type Service,
} from "../services/queueService";

const POLLING_INTERVAL_MS = 5000;

function isActiveStatus(status: QueueStatus): boolean {
  return [
    "waiting",
    "called",
    "serving",
  ].includes(status);
}

function formatQueueNumber(queueNumber: number): string {
  return String(queueNumber).padStart(3, "0");
}

function formatUpdatedTime(date: Date | null): string {
  if (!date) {
    return "Not updated yet";
  }

  return new Intl.DateTimeFormat("en-PH", {
    hour: "numeric",
    minute: "2-digit",
    second: "2-digit",
  }).format(date);
}

export default function DashboardPage() {
  const navigate = useNavigate();

  const {
    user,
    logoutUser,
  } = useAuth();

  const [services, setServices] = useState<Service[]>([]);

  const [
    currentQueue,
    setCurrentQueue,
  ] = useState<QueueEntry | null>(null);

  const [loadingData, setLoadingData] = useState(true);

  const [
    refreshingQueue,
    setRefreshingQueue,
  ] = useState(false);

  const [
    joiningServiceId,
    setJoiningServiceId,
  ] = useState<number | null>(null);

  const [loggingOut, setLoggingOut] = useState(false);

  const [lastUpdatedAt, setLastUpdatedAt] =
    useState<Date | null>(null);

  const [error, setError] = useState("");
  const [success, setSuccess] = useState("");

  const queueRequestInProgress = useRef(false);

  const lastQueueStatus = useRef<QueueStatus | null>(
    null,
  );

  const refreshCurrentQueue = useCallback(
    async (
      showLoadingIndicator = false,
      showErrors = false,
    ): Promise<void> => {
      if (queueRequestInProgress.current) {
        return;
      }

      queueRequestInProgress.current = true;

      if (showLoadingIndicator) {
        setRefreshingQueue(true);
      }

      try {
        const queueResult = await getCurrentQueue();

        const previousStatus = lastQueueStatus.current;
        const nextStatus = queueResult?.status ?? null;

        if (
          previousStatus !== null &&
          nextStatus !== previousStatus
        ) {
          if (nextStatus === "called") {
            setSuccess(
              "Your queue number has been called. Please proceed to the service counter.",
            );
          }

          if (nextStatus === "serving") {
            setSuccess(
              "Your transaction is now being served.",
            );
          }

          if (nextStatus === "completed") {
            setSuccess(
              "Your transaction has been completed.",
            );
          }

          if (nextStatus === "skipped") {
            setError(
              "Your queue number was skipped. Please contact a staff member.",
            );
          }

          if (nextStatus === "cancelled") {
            setError(
              "Your queue entry has been cancelled.",
            );
          }
        }

        lastQueueStatus.current = nextStatus;

        setCurrentQueue(queueResult);
        setLastUpdatedAt(new Date());
      } catch (requestError) {
        if (showErrors) {
          setError(getErrorMessage(requestError));
        }
      } finally {
        queueRequestInProgress.current = false;
        setRefreshingQueue(false);
      }
    },
    [],
  );

  useEffect(() => {
    let componentIsActive = true;

    async function loadDashboard(): Promise<void> {
      setError("");

      try {
        const [
          serviceResults,
          queueResult,
        ] = await Promise.all([
          getServices(),
          getCurrentQueue(),
        ]);

        if (!componentIsActive) {
          return;
        }

        setServices(serviceResults);
        setCurrentQueue(queueResult);

        lastQueueStatus.current =
          queueResult?.status ?? null;

        setLastUpdatedAt(new Date());
      } catch (requestError) {
        if (componentIsActive) {
          setError(getErrorMessage(requestError));
        }
      } finally {
        if (componentIsActive) {
          setLoadingData(false);
        }
      }
    }

    void loadDashboard();

    const pollingTimer = window.setInterval(() => {
      void refreshCurrentQueue(false, false);
    }, POLLING_INTERVAL_MS);

    return () => {
      componentIsActive = false;
      window.clearInterval(pollingTimer);
    };
  }, [refreshCurrentQueue]);

  async function handleJoinQueue(
    serviceId: number,
  ): Promise<void> {
    setError("");
    setSuccess("");
    setJoiningServiceId(serviceId);

    try {
      const newQueueEntry = await joinQueue(serviceId);

      setCurrentQueue(newQueueEntry);

      lastQueueStatus.current =
        newQueueEntry.status;

      setLastUpdatedAt(new Date());

      setSuccess(
        `You joined ${newQueueEntry.service.name}.`,
      );
    } catch (requestError) {
      setError(getErrorMessage(requestError));
    } finally {
      setJoiningServiceId(null);
    }
  }

  async function handleManualRefresh(): Promise<void> {
    setError("");

    await refreshCurrentQueue(true, true);
  }

  async function handleLogout(): Promise<void> {
    setError("");
    setLoggingOut(true);

    try {
      await logoutUser();
      navigate("/login");
    } catch (requestError) {
      setError(getErrorMessage(requestError));
    } finally {
      setLoggingOut(false);
    }
  }

  if (!user) {
    return null;
  }

  const hasActiveQueue =
    currentQueue !== null &&
    isActiveStatus(currentQueue.status);

  return (
    <main className="dashboard-page">
      <header className="dashboard-header">
        <div>
          <p className="eyebrow">QUEUEWISE</p>
          <strong>Customer Portal</strong>
        </div>

        <button
          className="secondary-button"
          type="button"
          onClick={handleLogout}
          disabled={loggingOut}
        >
          {loggingOut ? "Logging out..." : "Log out"}
        </button>
      </header>

      <section className="dashboard-content">
        <div className="welcome-block">
          <p className="eyebrow">
            CUSTOMER DASHBOARD
          </p>

          <h1>Welcome, {user.name}.</h1>

          <p className="supporting-text">
            Select a service and monitor your digital queue
            status.
          </p>
        </div>

        {error && (
          <div
            className="form-error dashboard-message"
            role="alert"
          >
            {error}
          </div>
        )}

        {success && (
          <div
            className="form-success dashboard-message"
            role="status"
          >
            {success}
          </div>
        )}

        {loadingData ? (
          <p className="dashboard-loading">
            Loading services...
          </p>
        ) : (
          <>
            <section className="queue-section">
              <div className="section-heading">
                <div>
                  <p className="eyebrow">
                    CURRENT QUEUE
                  </p>

                  <h2>Your queue status</h2>
                </div>

                <div className="queue-refresh-controls">
                  <span className="queue-updated-time">
                    Updated{" "}
                    {formatUpdatedTime(lastUpdatedAt)}
                  </span>

                  <button
                    className="secondary-button"
                    type="button"
                    onClick={() =>
                      void handleManualRefresh()
                    }
                    disabled={refreshingQueue}
                  >
                    {refreshingQueue
                      ? "Refreshing..."
                      : "Refresh"}
                  </button>
                </div>
              </div>

              {currentQueue ? (
                <article
                  className={`active-queue-card queue-state-${currentQueue.status}`}
                >
                  <div className="queue-number-block">
                    <span>Your number</span>

                    <strong>
                      {formatQueueNumber(
                        currentQueue.queue_number,
                      )}
                    </strong>
                  </div>

                  <div className="queue-details">
                    <div>
                      <span>Service</span>

                      <strong>
                        {currentQueue.service.name}
                      </strong>
                    </div>

                    <div>
                      <span>Status</span>

                      <strong className="status-text">
                        {currentQueue.status}
                      </strong>
                    </div>

                    <div>
                      <span>People ahead</span>

                      <strong>
                        {hasActiveQueue
                          ? currentQueue.people_ahead
                          : "—"}
                      </strong>
                    </div>

                    <div>
                      <span>Estimated wait</span>

                      <strong>
                        {hasActiveQueue
                          ? `${currentQueue.estimated_wait_minutes} minutes`
                          : "Finished"}
                      </strong>
                    </div>
                  </div>

                  {currentQueue.status === "called" && (
                    <div className="queue-alert">
                      Your number is being called. Please
                      proceed to the counter.
                    </div>
                  )}

                  {currentQueue.status === "serving" && (
                    <div className="queue-alert">
                      Your transaction is currently being
                      served.
                    </div>
                  )}

                  {currentQueue.status === "completed" && (
                    <div className="queue-completed-message">
                      Your transaction has been completed.
                      You may join another queue if needed.
                    </div>
                  )}
                </article>
              ) : (
                <div className="empty-queue">
                  <strong>No queue record today</strong>

                  <p>
                    Choose one of the available services
                    below.
                  </p>
                </div>
              )}
            </section>

            <section className="services-section">
              <div className="section-heading">
                <div>
                  <p className="eyebrow">
                    AVAILABLE SERVICES
                  </p>

                  <h2>Select a service</h2>
                </div>

                <span className="service-count">
                  {services.length} services
                </span>
              </div>

              <div className="services-grid">
                {services.map((service) => {
                  const isJoining =
                    joiningServiceId === service.id;

                  return (
                    <article
                      className="service-card"
                      key={service.id}
                    >
                      <div>
                        <span className="service-time">
                          Approximately{" "}
                          {
                            service
                              .average_service_minutes
                          }{" "}
                          minutes
                        </span>

                        <h3>{service.name}</h3>

                        <p>
                          {service.description ??
                            "Service description unavailable."}
                        </p>
                      </div>

                      <button
                        className="primary-button"
                        type="button"
                        onClick={() =>
                          void handleJoinQueue(service.id)
                        }
                        disabled={
                          hasActiveQueue ||
                          joiningServiceId !== null
                        }
                      >
                        {isJoining
                          ? "Joining..."
                          : hasActiveQueue
                            ? "Queue already active"
                            : "Join queue"}
                      </button>
                    </article>
                  );
                })}
              </div>
            </section>
          </>
        )}
      </section>
    </main>
  );
}