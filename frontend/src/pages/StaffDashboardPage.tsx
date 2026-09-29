import {
  useCallback,
  useRef,
  useEffect,
  useMemo,
  useState,
} from "react";

import {
  useNavigate,
} from "react-router-dom";

import { useAuth } from "../context/AuthContext";
import { getErrorMessage } from "../lib/getErrorMessage";

import {
  getServices,
  type Service,
} from "../services/queueService";

import {
  callNextCustomer,
  completeCustomer,
  getStaffQueue,
  serveCustomer,
  skipCustomer,
  type StaffQueueEntry,
} from "../services/staffService";

const STAFF_POLLING_INTERVAL_MS = 5000;

function formatQueueNumber(
  queueNumber: number,
): string {
  return String(queueNumber).padStart(
    3,
    "0",
  );
}

function formatTime(
  value: string | null,
): string {
  if (!value) {
    return "—";
  }

  const date = new Date(value);

  if (Number.isNaN(date.getTime())) {
    return "—";
  }

  return new Intl.DateTimeFormat(
    "en-PH",
    {
      hour: "numeric",
      minute: "2-digit",
    },
  ).format(date);
}

export default function StaffDashboardPage() {
  const navigate = useNavigate();

  const {
    user,
    logoutUser,
  } = useAuth();

  const [services, setServices] =
    useState<Service[]>([]);

  const [
    queueEntries,
    setQueueEntries,
  ] = useState<StaffQueueEntry[]>([]);

  const [
    selectedServiceId,
    setSelectedServiceId,
  ] = useState<number | null>(null);

  const [loading, setLoading] =
    useState(true);

  const [refreshing, setRefreshing] =
    useState(false);

  const [
    actionLoading,
    setActionLoading,
  ] = useState<string | null>(null);

  const [loggingOut, setLoggingOut] =
    useState(false);

  const [error, setError] =
    useState("");

  const [success, setSuccess] =
    useState("");

  const staffRefreshInProgress = useRef(false);
  const staffMutationInProgress = useRef(false);
  const queueRevision = useRef(0);

  const loadStaffData = useCallback(
    async (
      showMainLoader = true,
      showRefreshIndicator = false,
    ): Promise<void> => {
      if (staffRefreshInProgress.current || staffMutationInProgress.current) {
        return;
      }

      staffRefreshInProgress.current = true;
      const requestRevision = queueRevision.current;

      if (showMainLoader) {
        setLoading(true);
      }

      if (showRefreshIndicator) {
        setRefreshing(true);
      }

      try {
        const [
          serviceResults,
          queueResults,
        ] = await Promise.all([
          getServices(),
          getStaffQueue(),
        ]);

        if (requestRevision !== queueRevision.current) {
          return;
        }

        setServices(serviceResults);
        setQueueEntries(queueResults);

        setSelectedServiceId(
          (currentServiceId) => {
            const currentStillExists =
              serviceResults.some(
                (service) =>
                  service.id === currentServiceId,
              );

            if (currentStillExists) {
              return currentServiceId;
            }

            return serviceResults[0]?.id ?? null;
          },
        );
      } catch (requestError) {
        if (requestRevision === queueRevision.current) {
          setError(getErrorMessage(requestError));
        }
      } finally {
        staffRefreshInProgress.current = false;
        setLoading(false);
        setRefreshing(false);
      }
    },
    [],
  );

  useEffect(() => {
    void loadStaffData(true, false);
  }, [loadStaffData]);

  useEffect(() => {
    const pollingTimer = window.setInterval(() => {
      if (actionLoading === null) {
        void loadStaffData(false, false);
      }
    }, STAFF_POLLING_INTERVAL_MS);

    return () => {
      window.clearInterval(pollingTimer);
    };
  }, [
    actionLoading,
    loadStaffData,
  ]);

  const selectedService =
    useMemo(
      () =>
        services.find(
          (service) =>
            service.id ===
            selectedServiceId,
        ) ?? null,
      [
        services,
        selectedServiceId,
      ],
    );

  const selectedServiceQueue =
    useMemo(
      () =>
        queueEntries.filter(
          (entry) =>
            entry.service.id ===
            selectedServiceId,
        ),
      [
        queueEntries,
        selectedServiceId,
      ],
    );

  const activeEntry =
    useMemo(
      () =>
        selectedServiceQueue.find(
          (entry) =>
            entry.status === "called" ||
            entry.status === "serving",
        ) ?? null,
      [selectedServiceQueue],
    );

  const waitingEntries =
    useMemo(
      () =>
        selectedServiceQueue.filter(
          (entry) =>
            entry.status === "waiting",
        ),
      [selectedServiceQueue],
    );

  const totalWaiting =
    queueEntries.filter(
      (entry) =>
        entry.status === "waiting",
    ).length;

  const totalActive =
    queueEntries.filter(
      (entry) =>
        entry.status === "called" ||
        entry.status === "serving",
    ).length;

  async function handleCallNext() {
    if (!selectedServiceId || staffMutationInProgress.current) {
      return;
    }

    staffMutationInProgress.current = true;
    queueRevision.current += 1;
    setError("");
    setSuccess("");
    setActionLoading("call-next");

    try {
      const calledEntry =
        await callNextCustomer(
          selectedServiceId,
        );

      setSuccess(
        `Queue ${formatQueueNumber(
          calledEntry.queue_number,
        )} has been called.`,
      );

      updateQueueEntry(calledEntry);
    } catch (requestError) {
      setError(
        getErrorMessage(requestError),
      );
    } finally {
      staffMutationInProgress.current = false;
      setActionLoading(null);
    }
  }

  function updateQueueEntry(updatedEntry: StaffQueueEntry): void {
    setQueueEntries((entries) => {
      const remainingEntries = entries.filter((entry) => entry.id !== updatedEntry.id);

      if (updatedEntry.status === "completed" || updatedEntry.status === "skipped") {
        return remainingEntries;
      }

      return [...remainingEntries, updatedEntry].sort((a, b) =>
        a.service.id - b.service.id || a.queue_number - b.queue_number,
      );
    });
  }

  async function handleTransition(
    queueEntryId: number,
    action: "serve" | "skip" | "complete",
  ) {
    if (staffMutationInProgress.current) {
      return;
    }

    staffMutationInProgress.current = true;
    queueRevision.current += 1;
    setError("");
    setSuccess("");

    setActionLoading(
      `${action}-${queueEntryId}`,
    );

    try {
      const transition = {
        serve: serveCustomer,
        skip: skipCustomer,
        complete: completeCustomer,
      }[action];
      const updatedEntry = await transition(queueEntryId);

      setSuccess(
        `Queue ${formatQueueNumber(
          updatedEntry.queue_number,
        )} is now ${updatedEntry.status}.`,
      );

      updateQueueEntry(updatedEntry);
    } catch (requestError) {
      setError(
        getErrorMessage(requestError),
      );
    } finally {
      staffMutationInProgress.current = false;
      setActionLoading(null);
    }
  }

  async function handleLogout() {
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

  return (
    <main className="staff-page">
      <header className="dashboard-header">
        <div>
          <p className="eyebrow">
            QUEUEWISE
          </p>

          <strong>
            Staff Operations
          </strong>
        </div>

        <div className="staff-header-actions">
          <div className="staff-identity">
            <span>{user.name}</span>
            <small>{user.role}</small>
          </div>

          <button
            className="secondary-button"
            type="button"
            onClick={() =>
              void loadStaffData(false, true)
            }
            disabled={refreshing}
          >
            {refreshing
              ? "Refreshing..."
              : "Refresh"}
          </button>

          <button
            className="secondary-button"
            type="button"
            onClick={handleLogout}
            disabled={loggingOut}
          >
            {loggingOut
              ? "Logging out..."
              : "Log out"}
          </button>
        </div>
      </header>

      <section className="staff-content">
        <div className="welcome-block">
          <p className="eyebrow">
            STAFF DASHBOARD
          </p>

          <h1>
            Manage today’s queue.
          </h1>

          <p className="supporting-text">
            Select a service, call the
            next customer, and mark
            completed transactions.
          </p>
        </div>

        {error && (
          <div
            className="form-error staff-message"
            role="alert"
          >
            {error}
          </div>
        )}

        {success && (
          <div
            className="form-success staff-message"
            role="status"
          >
            {success}
          </div>
        )}

        {loading ? (
          <p className="dashboard-loading">
            Loading today’s queue...
          </p>
        ) : (
          <>
            <section className="staff-summary-grid">
              <article className="staff-summary-card">
                <span>
                  Waiting customers
                </span>

                <strong>
                  {totalWaiting}
                </strong>
              </article>

              <article className="staff-summary-card">
                <span>
                  Called / serving
                </span>

                <strong>
                  {totalActive}
                </strong>
              </article>

              <article className="staff-summary-card">
                <span>
                  Active services
                </span>

                <strong>
                  {services.length}
                </strong>
              </article>
            </section>

            <section className="staff-service-section">
              <div className="section-heading">
                <div>
                  <p className="eyebrow">
                    SERVICES
                  </p>

                  <h2>
                    Select a queue
                  </h2>
                </div>
              </div>

              <div className="staff-service-tabs">
                {services.map(
                  (service) => {
                    const isSelected =
                      selectedServiceId ===
                      service.id;

                    return (
                      <button
                        className={
                          isSelected
                            ? "staff-service-tab active"
                            : "staff-service-tab"
                        }
                        type="button"
                        key={service.id}
                        onClick={() => {
                          setSelectedServiceId(
                            service.id,
                          );

                          setError("");
                          setSuccess("");
                        }}
                      >
                        <span>
                          {service.name}
                        </span>

                        <small>
                          {
                            queueEntries.filter(
                              (entry) =>
                                entry
                                  .service
                                  .id ===
                                service.id &&
                                entry.status ===
                                  "waiting",
                            ).length
                          }{" "}
                          waiting
                        </small>
                      </button>
                    );
                  },
                )}
              </div>
            </section>

            {selectedService ? (
              <>
                <section className="staff-operation-grid">
                  <article className="staff-current-card">
                    <div className="staff-card-heading">
                      <div>
                        <p className="eyebrow">
                          CURRENT CUSTOMER
                        </p>

                        <h2>
                          {
                            selectedService.name
                          }
                        </h2>
                      </div>

                      <span className="staff-status-badge">
                        {activeEntry
                          ? activeEntry.status
                          : "idle"}
                      </span>
                    </div>

                    {activeEntry ? (
                      <>
                        <div className="staff-called-number">
                          <span>
                            {activeEntry.status === "serving" ? "Now serving" : "Now calling"}
                          </span>

                          <strong>
                            {formatQueueNumber(
                              activeEntry
                                .queue_number,
                            )}
                          </strong>
                        </div>

                        <div className="staff-customer-details">
                          <div>
                            <span>
                              Customer
                            </span>

                            <strong>
                              {
                                activeEntry
                                  .user
                                  .name
                              }
                            </strong>
                          </div>

                          <div>
                            <span>
                              Email
                            </span>

                            <strong>
                              {
                                activeEntry
                                  .user
                                  .email
                              }
                            </strong>
                          </div>

                          <div>
                            <span>
                              Called at
                            </span>

                            <strong>
                              {formatTime(
                                activeEntry
                                  .called_at,
                              )}
                            </strong>
                          </div>
                        </div>

                        {activeEntry.status === "called" ? (
                          <div className="staff-header-actions">
                            <button
                              className="primary-button"
                              type="button"
                              onClick={() => void handleTransition(activeEntry.id, "serve")}
                              disabled={actionLoading !== null}
                            >
                              {actionLoading === `serve-${activeEntry.id}` ? "Starting..." : "Start serving"}
                            </button>
                            <button
                              className="secondary-button"
                              type="button"
                              onClick={() => void handleTransition(activeEntry.id, "skip")}
                              disabled={actionLoading !== null}
                            >
                              {actionLoading === `skip-${activeEntry.id}` ? "Skipping..." : "Skip customer"}
                            </button>
                          </div>
                        ) : (
                          <button
                            className="primary-button"
                            type="button"
                            onClick={() =>
                              void handleTransition(
                                activeEntry.id,
                                "complete",
                              )
                            }
                            disabled={actionLoading !== null}
                          >
                            {actionLoading === `complete-${activeEntry.id}`
                              ? "Completing..."
                              : "Mark as completed"}
                          </button>
                        )}
                      </>
                    ) : (
                      <div className="staff-idle-state">
                        <strong>
                          No customer currently
                          called
                        </strong>

                        <p>
                          Call the next waiting
                          customer for this service.
                        </p>

                        <button
                          className="primary-button"
                          type="button"
                          onClick={() =>
                            void handleCallNext()
                          }
                          disabled={
                            waitingEntries.length ===
                              0 ||
                            actionLoading !== null
                          }
                        >
                          {actionLoading ===
                          "call-next"
                            ? "Calling..."
                            : waitingEntries.length ===
                                0
                              ? "No customers waiting"
                              : "Call next customer"}
                        </button>
                      </div>
                    )}
                  </article>

                  <article className="staff-waiting-card">
                    <div className="staff-card-heading">
                      <div>
                        <p className="eyebrow">
                          WAITING LIST
                        </p>

                        <h2>
                          {
                            waitingEntries.length
                          }{" "}
                          waiting
                        </h2>
                      </div>
                    </div>

                    {waitingEntries.length >
                    0 ? (
                      <div className="staff-queue-list">
                        {waitingEntries.map(
                          (
                            queueEntry,
                            index,
                          ) => (
                            <article
                              className="staff-queue-row"
                              key={
                                queueEntry.id
                              }
                            >
                              <div className="staff-queue-position">
                                <span>
                                  {index + 1}
                                </span>
                              </div>

                              <div className="staff-queue-number">
                                <span>
                                  Queue
                                </span>

                                <strong>
                                  {formatQueueNumber(
                                    queueEntry
                                      .queue_number,
                                  )}
                                </strong>
                              </div>

                              <div className="staff-queue-person">
                                <strong>
                                  {
                                    queueEntry
                                      .user
                                      .name
                                  }
                                </strong>

                                <span>
                                  Joined{" "}
                                  {formatTime(
                                    queueEntry
                                      .joined_at,
                                  )}
                                </span>
                              </div>
                            </article>
                          ),
                        )}
                      </div>
                    ) : (
                      <div className="staff-empty-list">
                        <strong>
                          Waiting list is empty
                        </strong>

                        <p>
                          New customers will appear
                          here after joining this
                          service.
                        </p>
                      </div>
                    )}
                  </article>
                </section>
              </>
            ) : (
              <div className="staff-empty-list">
                <strong>
                  No services available
                </strong>

                <p>
                  Add an active service before
                  managing queues.
                </p>
              </div>
            )}
          </>
        )}
      </section>
    </main>
  );
}
