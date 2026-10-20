import HealthStatus from './components/HealthStatus'
import './App.css'

function App() {
  return (
    <section id="center">
      <div>
        <h1>TelmanFit</h1>
        <p>Клієнтський застосунок онлайн-фітнес-супроводу</p>
      </div>
      <HealthStatus />
    </section>
  )
}

export default App
