import { createContext, useContext, useEffect, useState } from 'react';

export const CoreviaContext = createContext(null);

/** Wrap children with CoreviaContext.Provider value={client}. Supply a stable payload object. */
export function useCoreviaAction(target, payload) {
  const client = useContext(CoreviaContext);
  if (!client) throw new Error('CoreviaContext.Provider is required.');
  const [state, setState] = useState({ data: null, loading: true, error: null });
  useEffect(() => {
    const controller = new AbortController();
    let active = true;
    setState({ data: null, loading: true, error: null });
    client.call(target, payload, { signal: controller.signal }).then(
      result => { if (active) setState({ data: result.data, loading: false, error: null }); },
      error => { if (active) setState({ data: null, loading: false, error }); },
    );
    return () => { active = false; controller.abort(); };
  }, [client, target, payload]);
  return state;
}
