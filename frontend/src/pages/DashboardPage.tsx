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
  cancelQueue,
  getCurrentQueue,
  getServices,
  joinQueue,
  type QueueEntry,
  type QueueStatus,
  type Service,
} from "../services/queueService";

import "./DashboardPage.css";

const POLLING_INTERVAL_MS = 5000;

function isActiveStatus(
  status: QueueStatus,
): boolean {
  return [
    "waiting",
    "called",
    "serving",
  ].includes(status);
}

function formatQueueNumber(
  queueNumber: number,
): string {
  return String(queueNumber).padStart(
    3,
    "0",
  );
}

function formatUpdatedTime(
  date: Date | null,
): string {
  if (!date) {
    return "Not updated yet";
  }

  return new Intl.DateTimeFormat(
    "en-PH",
    {
      hour: "numeric",
      minute: "2-digit",
      second: "2-digit",
    },
  ).format(date);
}

export default function DashboardPage() {
  const navigate = useNavigate();

  const {
    user,
    logoutUser,
  } = useAuth();

  const [services, setServices] =
    useState<Service[]>([]);

  const [
    currentQueue,
    setCurrentQueue,
  ] = useState<QueueEntry | null>(null);

  const [loadingData, setLoadingData] =
    useState(true);

  const [
    refreshingQueue,
    setRefreshingQueue,
  ] = useState(false);

  const [
    joiningServiceId,
    setJoiningServiceId,
  ] = useState<number | null>(null);

  const [
    cancellingQueue,
    setCancellingQueue,
  ] = useState(false);

  const [loggingOut, setLoggingOut] =
    useState(false);

  const [
    lastUpdatedAt,
    setLastUpdatedAt,
  ] = useState<Date | null>(null);

  const [error, setError] =
    useState("");

  const [success, setSuccess] =
    useState("");

  /*
   * Prevent two polling requests from running
   * at the same time.
   */
  const queueRequestInProgress =
    useRef(false);

  const queueMutationInProgress = useRef(false);
  const queueRevision = useRef(0);

  /*
   * Remember the previous queue status so React
   * can detect changes.
   */
  const lastQueueStatus =
    useRef<QueueStatus | null>(null);

  const refreshCurrentQueue =
    useCallback(
      async (
        showLoadingIndicator = false,
        showErrors = false,
      ): Promise<void> => {
        if (queueRequestInProgress.current || queueMutationInProgress.current) {
          return;
        }

        queueRequestInProgress.current = true;
        const requestRevision = queueRevision.current;

        if (showLoadingIndicator) {
          setRefreshingQueue(true);
        }

        try {
          const queueResult =
            await getCurrentQueue();

          if (requestRevision !== queueRevision.current) {
            return;
          }

          const previousStatus =
            lastQueueStatus.current;

          const nextStatus =
            queueResult?.status ?? null;

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

            if (nextStatus === "cancelled") {
              setSuccess(
                "Your queue entry has been cancelled.",
              );
            }

            if (nextStatus === "skipped") {
              setError(
                "Your queue number was skipped. Please contact a staff member.",
              );
            }
          }

          lastQueueStatus.current =
            nextStatus;

          setCurrentQueue(queueResult);
          setLastUpdatedAt(new Date());
        } catch (requestError) {
          if (showErrors && requestRevision === queueRevision.current) {
            setError(
              getErrorMessage(requestError),
            );
          }
        } finally {
          queueRequestInProgress.current =
            false;

          setRefreshingQueue(false);
        }
      },
      [],
    );

  useEffect(() => {
    let componentIsActive = true;

    async function loadDashboard():
      Promise<void> {
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
          setError(
            getErrorMessage(requestError),
          );
        }
      } finally {
        if (componentIsActive) {
          setLoadingData(false);
        }
      }
    }

    void loadDashboard();

    return () => {
      componentIsActive = false;
    };
  }, []);

  useEffect(() => {
    if (loadingData) {
      return;
    }

    const pollingTimer =
      window.setInterval(() => {
        void refreshCurrentQueue(
          false,
          false,
        );
      }, POLLING_INTERVAL_MS);

    return () => {
      window.clearInterval(
        pollingTimer,
      );
    };
  }, [loadingData, refreshCurrentQueue]);

  async function handleJoinQueue(
    serviceId: number,
  ): Promise<void> {
    if (queueMutationInProgress.current) {
      return;
    }

    queueMutationInProgress.current = true;
    queueRevision.current += 1;
    setError("");
    setSuccess("");
    setJoiningServiceId(serviceId);

    try {
      const newQueueEntry =
        await joinQueue(serviceId);

      setCurrentQueue(newQueueEntry);

      lastQueueStatus.current =
        newQueueEntry.status;

      setLastUpdatedAt(new Date());

      setSuccess(
        `You joined ${newQueueEntry.service.name}.`,
      );
    } catch (requestError) {
      setError(
        getErrorMessage(requestError),
      );
    } finally {
      queueMutationInProgress.current = false;
      setJoiningServiceId(null);
    }
  }

  async function handleCancelQueue():
    Promise<void> {
    if (!currentQueue || queueMutationInProgress.current) {
      return;
    }

    const confirmed = window.confirm(
      "Are you sure you want to cancel your queue entry?",
    );

    if (!confirmed) {
      return;
    }

    queueMutationInProgress.current = true;
    queueRevision.current += 1;
    setError("");
    setSuccess("");
    setCancellingQueue(true);

    try {
      const cancelledQueue =
        await cancelQueue(currentQueue.id);

      setCurrentQueue(cancelledQueue);

      lastQueueStatus.current =
        cancelledQueue.status;

      setLastUpdatedAt(new Date());

      setSuccess(
        "Your queue entry has been cancelled.",
      );
    } catch (requestError) {
      setError(
        getErrorMessage(requestError),
      );
    } finally {
      queueMutationInProgress.current = false;
      setCancellingQueue(false);
    }
  }

  async function handleManualRefresh():
    Promise<void> {
    setError("");

    await refreshCurrentQueue(
      true,
      true,
    );
  }

  async function handleLogout():
    Promise<void> {
    setError("");
    setLoggingOut(true);

    try {
      await logoutUser();
      navigate("/login");
    } catch (requestError) {
      setError(
        getErrorMessage(requestError),
      );
    } finally {
      setLoggingOut(false);
    }
  }

  if (!user) {
    return null;
  }

  const hasActiveQueue =
    currentQueue !== null &&
    isActiveStatus(
      currentQueue.status,
    );

  return (
    <main className="dashboard-page">
      <header className="dashboard-header">
        <div>
          <p className="eyebrow">
            QUEUEWISE
          </p>

          <strong>
            Customer Portal
          </strong>
        </div>

        <button
          className="secondary-button"
          type="button"
          onClick={() =>
            void handleLogout()
          }
          disabled={loggingOut}
        >
          {loggingOut
            ? "Logging out..."
            : "Log out"}
        </button>
      </header>

      <section className="dashboard-content">
        <div className="welcome-block">
          <p className="eyebrow">
            CUSTOMER DASHBOARD
          </p>

          <h1>
            Welcome, {user.name}.
          </h1>

          <p className="supporting-text">
            Select a service and monitor
            your digital queue status.
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

                  <h2>
                    Your queue status
                  </h2>
                </div>

                <div className="queue-refresh-controls">
                  <span className="queue-updated-time">
                    Updated{" "}
                    {formatUpdatedTime(
                      lastUpdatedAt,
                    )}
                  </span>

                  <button
                    className="secondary-button"
                    type="button"
                    onClick={() =>
                      void handleManualRefresh()
                    }
                    disabled={
                      refreshingQueue
                    }
                  >
                    {refreshingQueue
                      ? "Refreshing..."
                      : "Refresh"}
                  </button>
                </div>
              </div>

              {currentQueue ? (
                <article
                  className={
                    `active-queue-card ` +
                    `queue-state-${currentQueue.status}`
                  }
                >
                  <div className="queue-number-block">
                    <span>
                      Your number
                    </span>

                    <strong>
                      {formatQueueNumber(
                        currentQueue.queue_number,
                      )}
                    </strong>
                  </div>

                  <div className="queue-details">
                    <div>
                      <span>
                        Service
                      </span>

                      <strong>
                        {
                          currentQueue
                            .service
                            .name
                        }
                      </strong>
                    </div>

                    <div>
                      <span>
                        Status
                      </span>

                      <strong className="status-text">
                        {
                          currentQueue.status
                        }
                      </strong>
                    </div>

                    <div>
                      <span>
                        People ahead
                      </span>

                      <strong>
                        {hasActiveQueue
                          ? currentQueue
                              .people_ahead
                          : "—"}
                      </strong>
                    </div>

                    <div>
                      <span>
                        Estimated wait
                      </span>

                      <strong>
                        {hasActiveQueue
                          ? `${currentQueue.estimated_wait_minutes} minutes`
                          : "Finished"}
                      </strong>
                    </div>
                  </div>

                  {currentQueue.status ===
                    "waiting" && (
                    <div className="queue-action-row">
                      <button
                        className="danger-button"
                        type="button"
                        onClick={() =>
                          void handleCancelQueue()
                        }
                        disabled={
                          cancellingQueue
                        }
                      >
                        {cancellingQueue
                          ? "Cancelling..."
                          : "Cancel queue"}
                      </button>
                    </div>
                  )}

                  {currentQueue.status ===
                    "called" && (
                    <div className="queue-alert">
                      Your number is being
                      called. Please proceed
                      to the counter.
                    </div>
                  )}

                  {currentQueue.status ===
                    "serving" && (
                    <div className="queue-alert">
                      Your transaction is
                      currently being served.
                    </div>
                  )}

                  {currentQueue.status ===
                    "completed" && (
                    <div className="queue-completed-message">
                      Your transaction has
                      been completed. You may
                      join another queue if
                      needed.
                    </div>
                  )}

                  {currentQueue.status ===
                    "cancelled" && (
                    <div className="queue-cancelled-message">
                      Your queue entry was
                      cancelled. You may
                      select another service
                      below.
                    </div>
                  )}

                  {currentQueue.status ===
                    "skipped" && (
                    <div className="queue-skipped-message">
                      Your queue number was
                      skipped. Please contact
                      a staff member.
                    </div>
                  )}
                </article>
              ) : (
                <div className="empty-queue">
                  <strong>
                    No queue record today
                  </strong>

                  <p>
                    Choose one of the
                    available services below.
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

                  <h2>
                    Select a service
                  </h2>
                </div>

                <span className="service-count">
                  {services.length} services
                </span>
              </div>

              <div className="services-grid">
                {services.map(
                  (service) => {
                    const isJoining =
                      joiningServiceId ===
                      service.id;

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

                          <h3>
                            {service.name}
                          </h3>

                          <p>
                            {service.description ??
                              "Service description unavailable."}
                          </p>
                        </div>

                        <button
                          className="primary-button"
                          type="button"
                          onClick={() =>
                            void handleJoinQueue(
                              service.id,
                            )
                          }
                          disabled={
                            hasActiveQueue ||
                            joiningServiceId !==
                              null
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
                  },
                )}
              </div>
            </section>
          </>
        )}
      </section>
    </main>
  );
}
